<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Weave\Application\Db\MySQL\Model\EdgeResource;
use Semitexa\Weave\Application\Db\MySQL\Model\NodeResource;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Edge;
use Semitexa\Weave\Domain\Model\Node;
use Semitexa\Weave\Domain\Model\Relation;
use Semitexa\Weave\Domain\Model\TitleKey;

/**
 * The Weave graph store — idempotent upsert of typed nodes/edges over the ORM
 * (tables `weave_node` / `weave_edge`). Nodes dedup by (kind, normalised title);
 * edges by (from, to, relation), so the same entity/relationship woven twice
 * doesn't duplicate. Properties are a JSON bag on the row (the store en/decodes
 * it and wraps rows into {@see Node}/{@see Edge} value objects).
 *
 * The OrmManager is injected for container-managed callers and lazily built for
 * callers that `new` this outside DI (same convention as ConversationStore).
 */
#[SatisfiesServiceContract(of: GraphStoreInterface::class)]
class GraphStore implements GraphStoreInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $nodeRepo = null;
    private ?DomainRepository $edgeRepo = null;

    public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node
    {
        $title = trim($title);
        $titleKey = $this->titleKey($title);
        $now = new \DateTimeImmutable();

        $existing = $this->existingNodeByKey($kind, $titleKey);
        if ($existing instanceof NodeResource) {
            return $this->mergeIntoNode($existing, $title !== '' ? $title : $existing->title, $properties, $source, $now);
        }

        // Near-duplicate guard: different phrasings of the same thing reduce to
        // one sorted content-token set ("Semitexa documentation" == "documentation
        // for Semitexa"). Converge onto the existing node — its (earlier) title
        // stays canonical, new properties merge in. Bounded same-kind scan; the
        // graph is a personal world, not a warehouse.
        $nearDup = $this->findNearDuplicateNode($kind, $title);
        if ($nearDup instanceof NodeResource) {
            return $this->mergeIntoNode($nearDup, $nearDup->title, $properties, $source, $now);
        }

        $row = new NodeResource(
            id: Uuid7::generate(),
            kind: $kind->value,
            title: $title,
            title_key: $titleKey,
            properties_json: $this->encode($properties),
            source: $source,
            created_at: $now,
            updated_at: $now,
        );

        try {
            $this->nodes()->insert($row);

            return $this->toNode($row);
        } catch (\Throwable $e) {
            // Lost the first-write race: a concurrent upsertNode of the same
            // (kind, title_key) inserted first, and the unique index
            // uniq_weave_node_kind_title rejected ours (both coroutines passed
            // the exact-match and near-dup scans before either inserted). The
            // winner's row exists now — converge onto it and merge our
            // properties in, rather than propagating a spurious failure and
            // losing this upsert. If no row is present it was a real error, not
            // the race: rethrow.
            $winner = $this->existingNodeByKey($kind, $titleKey);
            if ($winner instanceof NodeResource) {
                return $this->mergeIntoNode($winner, $title !== '' ? $title : $winner->title, $properties, $source, $now);
            }

            throw $e;
        }
    }

    /** Exact-match lookup by (kind, normalised title). Also the post-collision re-read. */
    protected function existingNodeByKey(NodeKind $kind, string $titleKey): ?NodeResource
    {
        $row = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->where(NodeResource::column('title_key'), Operator::Equals, $titleKey)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());

        return $row instanceof NodeResource ? $row : null;
    }

    /** Bounded same-kind scan converging different phrasings onto one node via the content-token set. */
    protected function findNearDuplicateNode(NodeKind $kind, string $title): ?NodeResource
    {
        $tokenKey = TitleKey::tokenSet($title);
        $sameKind = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->limit(500)
            ->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry());
        foreach ($sameKind as $candidate) {
            if (TitleKey::tokenSet($candidate->title) === $tokenKey) {
                return $candidate;
            }
        }

        return null;
    }

    /** Merge properties/source into an existing node row (last-title-wins per the caller's resolution) and persist. */
    private function mergeIntoNode(NodeResource $existing, string $title, array $properties, string $source, \DateTimeImmutable $now): Node
    {
        $row = new NodeResource(
            id: $existing->id,
            kind: $existing->kind,
            title: $title,
            title_key: $existing->title_key,
            properties_json: $this->encode(array_merge($this->decode($existing->properties_json), $properties)),
            source: $existing->source !== '' ? $existing->source : $source,
            created_at: $existing->created_at,
            updated_at: $now,
        );
        $this->nodes()->update($row);

        return $this->toNode($row);
    }

    /**
     * Merge $dropId into $keepId: every edge is repointed to the kept node
     * (collisions with an existing (from,to,relation) edge and would-be
     * self-loops are dropped), properties merge (the kept node wins on
     * conflicts), and the duplicate node is removed. The primitive behind
     * near-duplicate cleanup (weave:dedup).
     */
    public function mergeNodes(string $keepId, string $dropId): void
    {
        if ($keepId === $dropId) {
            return;
        }
        $keep = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $keepId)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());
        $drop = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $dropId)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());
        if (!$keep instanceof NodeResource || !$drop instanceof NodeResource) {
            return;
        }

        foreach (array_merge($this->edgesFrom($dropId), $this->edgesTo($dropId)) as $edge) {
            $newFrom = $edge->fromId === $dropId ? $keepId : $edge->fromId;
            $newTo = $edge->toId === $dropId ? $keepId : $edge->toId;
            $this->removeEdge($edge->id);
            if ($newFrom === $newTo) {
                continue; // a self-loop carries no information
            }
            // addEdge() dedups on (from, to, relation), so collisions collapse.
            $this->addEdge($newFrom, $newTo, $edge->relation, $edge->weight, $edge->source);
        }

        $now = new \DateTimeImmutable();
        $this->nodes()->update(new NodeResource(
            id: $keep->id,
            kind: $keep->kind,
            title: $keep->title,
            title_key: $keep->title_key,
            properties_json: $this->encode(array_merge(
                $this->decode($drop->properties_json),
                $this->decode($keep->properties_json),
            )),
            source: $keep->source !== '' ? $keep->source : $drop->source,
            created_at: $keep->created_at,
            updated_at: $now,
        ));
        $this->nodes()->delete($drop);
    }

    public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): Edge
    {
        $relation = Relation::normalise($relation);
        $weight = max(0, min(100, $weight));
        $now = new \DateTimeImmutable();

        $existing = $this->existingEdgeByTriple($fromId, $toId, $relation);
        if ($existing instanceof EdgeResource) {
            return $this->mergeIntoEdge($existing, $fromId, $toId, $relation, $weight, $source, $now);
        }

        $row = new EdgeResource(
            id: Uuid7::generate(),
            from_id: $fromId,
            to_id: $toId,
            relation: $relation,
            weight: $weight,
            source: $source,
            created_at: $now,
            updated_at: $now,
        );

        try {
            $this->edges()->insert($row);

            return $this->toEdge($row);
        } catch (\Throwable $e) {
            // Lost the first-write race: a concurrent addEdge of the same
            // (from_id, to_id, relation) inserted first and the unique index
            // uniq_weave_edge_triple rejected ours. Converge onto the winner
            // and fold in our weight (max) instead of failing and losing the
            // assertion. No row present ⇒ a real error, not the race: rethrow.
            $winner = $this->existingEdgeByTriple($fromId, $toId, $relation);
            if ($winner instanceof EdgeResource) {
                return $this->mergeIntoEdge($winner, $fromId, $toId, $relation, $weight, $source, $now);
            }

            throw $e;
        }
    }

    /** Exact-match lookup by (from_id, to_id, relation). Also the post-collision re-read. */
    protected function existingEdgeByTriple(string $fromId, string $toId, string $relation): ?EdgeResource
    {
        $row = $this->edges()->query()
            ->where(EdgeResource::column('from_id'), Operator::Equals, $fromId)
            ->where(EdgeResource::column('to_id'), Operator::Equals, $toId)
            ->where(EdgeResource::column('relation'), Operator::Equals, $relation)
            ->fetchOneAs(EdgeResource::class, $this->orm()->getMapperRegistry());

        return $row instanceof EdgeResource ? $row : null;
    }

    /** Fold a new assertion into an existing edge: weight upgrades (max), source fills if empty, then persist. */
    private function mergeIntoEdge(EdgeResource $existing, string $fromId, string $toId, string $relation, int $weight, string $source, \DateTimeImmutable $now): Edge
    {
        $row = new EdgeResource(
            id: $existing->id,
            from_id: $fromId,
            to_id: $toId,
            relation: $relation,
            weight: max($weight, $existing->weight), // an asserted edge upgrades an inferred one
            source: $existing->source !== '' ? $existing->source : $source,
            created_at: $existing->created_at,
            updated_at: $now,
        );
        $this->edges()->update($row);

        return $this->toEdge($row);
    }

    public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node
    {
        $existing = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());
        if (!$existing instanceof NodeResource) {
            return null;
        }

        $newTitle = ($title !== null && trim($title) !== '') ? trim($title) : $existing->title;
        $row = new NodeResource(
            id: $existing->id,
            kind: $existing->kind,
            title: $newTitle,
            title_key: $this->titleKey($newTitle),
            properties_json: $this->encode(array_merge($this->decode($existing->properties_json), $properties)),
            source: $existing->source,
            created_at: $existing->created_at,
            updated_at: new \DateTimeImmutable(),
        );
        $this->nodes()->update($row);

        return $this->toNode($row);
    }

    public function node(string $id): ?Node
    {
        $row = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());

        return $row instanceof NodeResource ? $this->toNode($row) : null;
    }

    public function nodesByKind(NodeKind $kind, int $limit = 0): array
    {
        $query = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc);
        if ($limit > 0) {
            $query->limit($limit);
        }

        return array_map($this->toNode(...), $query->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry()));
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $rows = $this->nodes()->query()
            ->whereLike(NodeResource::column('title'), '%' . $term . '%')
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc)
            ->limit($limit)
            ->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry());

        return array_map($this->toNode(...), $rows);
    }

    public function neighborhood(string $nodeId): array
    {
        // 2 edge queries + 1 node query, regardless of degree — was O(neighbours)
        // (one node() SELECT per neighbour plus one for the centre).
        $edges = $this->edgesTouching([$nodeId]);

        $neighborIds = [];
        foreach ($edges as $edge) {
            $other = $edge->fromId === $nodeId ? $edge->toId : $edge->fromId;
            $neighborIds[$other] = true;
        }
        $neighborIds = array_keys($neighborIds);

        $nodeMap = $this->nodesByIds(array_merge([$nodeId], $neighborIds));

        $neighbors = [];
        foreach ($neighborIds as $id) {
            if (isset($nodeMap[$id])) {
                $neighbors[] = $nodeMap[$id];
            }
        }

        return ['node' => $nodeMap[$nodeId] ?? null, 'edges' => $edges, 'neighbors' => $neighbors];
    }

    public function subgraph(string $nodeId, int $depth = 1): array
    {
        $depth = max(1, min(3, $depth));
        $centerMap = $this->nodesByIds([$nodeId]);
        if (!isset($centerMap[$nodeId])) {
            return ['nodes' => [], 'edges' => []];
        }

        /** @var array<string, \Semitexa\Weave\Domain\Model\Node> $nodes */
        $nodes = [$nodeId => $centerMap[$nodeId]];
        $frontier = [$nodeId];

        // Batched BFS: 2 edge queries + 1 node query PER HOP (depth ≤ 3), instead
        // of 3 SELECTs per visited node (edgesFrom + edgesTo + node) — O(hops),
        // not O(N). A hub node no longer explodes the query count.
        for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
            $candidateIds = [];
            foreach ($this->edgesTouching($frontier) as $edge) {
                foreach ([$edge->fromId, $edge->toId] as $end) {
                    if (!isset($nodes[$end])) {
                        $candidateIds[$end] = true;
                    }
                }
            }
            $candidateIds = array_keys($candidateIds);
            $newNodes = $this->nodesByIds($candidateIds);

            $next = [];
            foreach ($candidateIds as $id) {
                if (isset($newNodes[$id])) {
                    $nodes[$id] = $newNodes[$id];
                    $next[] = $id;
                }
            }
            $frontier = $next;
        }

        // Every internal edge of the discovered node set, in one batched pass.
        // This subsumes the old per-node BFS edge collection AND the rim
        // cross-link sweep (edges between two included nodes reached via other
        // paths); dangling edges to non-existent nodes are naturally excluded.
        $edges = [];
        foreach ($this->edgesTouching(array_keys($nodes)) as $edge) {
            if (isset($nodes[$edge->fromId]) && isset($nodes[$edge->toId])) {
                $edges[$edge->id] = $edge;
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    public function graph(int $limit = 500): array
    {
        $limit = max(1, $limit);
        $nodeRows = $this->nodes()->query()
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc)
            ->limit($limit)
            ->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry());
        $edgeRows = $this->edges()->query()
            ->limit($limit * 8)
            ->fetchAllAs(EdgeResource::class, $this->orm()->getMapperRegistry());

        return [
            'nodes' => array_map($this->toNode(...), $nodeRows),
            'edges' => array_map($this->toEdge(...), $edgeRows),
        ];
    }

    public function removeNode(string $id): void
    {
        foreach (array_merge($this->edgesFrom($id), $this->edgesTo($id)) as $edge) {
            $this->removeEdge($edge->id);
        }
        $row = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());
        if ($row instanceof NodeResource) {
            $this->nodes()->delete($row);
        }
    }

    public function removeEdge(string $id): void
    {
        $row = $this->edges()->query()
            ->where(EdgeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(EdgeResource::class, $this->orm()->getMapperRegistry());
        if ($row instanceof EdgeResource) {
            $this->edges()->delete($row);
        }
    }

    public function counts(): array
    {
        return [
            'nodes' => $this->nodes()->query()->count(),
            'edges' => $this->edges()->query()->count(),
        ];
    }

    /**
     * Batched node hydration for a set of ids — one `WHERE id IN(...)` instead
     * of one SELECT per id. Existing nodes only; keyed by id.
     *
     * @param list<string> $ids
     * @return array<string, Node>
     */
    private function nodesByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $rows = $this->nodes()->query()
            ->whereIn(NodeResource::column('id'), $ids)
            ->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry());

        $map = [];
        foreach ($rows as $row) {
            $map[$row->id] = $this->toNode($row);
        }

        return $map;
    }

    /**
     * Every edge with an endpoint in $ids, deduped by edge id — two batched
     * `WHERE from_id IN(...)` / `WHERE to_id IN(...)` queries (the query builder
     * has no OR-group, so a union of two INs replaces it) instead of a pair of
     * SELECTs per node.
     *
     * @param list<string> $ids
     * @return list<Edge>
     */
    private function edgesTouching(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $registry = $this->orm()->getMapperRegistry();
        $from = $this->edges()->query()
            ->whereIn(EdgeResource::column('from_id'), $ids)
            ->fetchAllAs(EdgeResource::class, $registry);
        $to = $this->edges()->query()
            ->whereIn(EdgeResource::column('to_id'), $ids)
            ->fetchAllAs(EdgeResource::class, $registry);

        $byId = [];
        foreach (array_merge($from, $to) as $row) {
            $byId[$row->id] = $row;
        }

        return array_map($this->toEdge(...), array_values($byId));
    }

    /** @return list<Edge> */
    private function edgesFrom(string $nodeId): array
    {
        $rows = $this->edges()->query()
            ->where(EdgeResource::column('from_id'), Operator::Equals, $nodeId)
            ->fetchAllAs(EdgeResource::class, $this->orm()->getMapperRegistry());

        return array_map($this->toEdge(...), $rows);
    }

    /** @return list<Edge> */
    private function edgesTo(string $nodeId): array
    {
        $rows = $this->edges()->query()
            ->where(EdgeResource::column('to_id'), Operator::Equals, $nodeId)
            ->fetchAllAs(EdgeResource::class, $this->orm()->getMapperRegistry());

        return array_map($this->toEdge(...), $rows);
    }

    private function titleKey(string $title): string
    {
        return TitleKey::exact($title);
    }

    /** @param array<string, mixed> $properties */
    private function encode(array $properties): string
    {
        return (string) json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function toNode(NodeResource $row): Node
    {
        return new Node(
            id: $row->id,
            kind: NodeKind::from($row->kind),
            title: $row->title,
            properties: $this->decode($row->properties_json),
            source: $row->source,
            createdAt: $row->created_at->format('c'),
            updatedAt: $row->updated_at->format('c'),
        );
    }

    private function toEdge(EdgeResource $row): Edge
    {
        return new Edge(
            id: $row->id,
            fromId: $row->from_id,
            toId: $row->to_id,
            relation: $row->relation,
            weight: $row->weight,
            source: $row->source,
            createdAt: $row->created_at->format('c'),
            updatedAt: $row->updated_at->format('c'),
        );
    }

    private function nodes(): DomainRepository
    {
        return $this->nodeRepo ??= $this->orm()->repository(NodeResource::class, NodeResource::class);
    }

    private function edges(): DomainRepository
    {
        return $this->edgeRepo ??= $this->orm()->repository(EdgeResource::class, EdgeResource::class);
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
