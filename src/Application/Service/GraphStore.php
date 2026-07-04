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
final class GraphStore implements GraphStoreInterface
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

        $existing = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->where(NodeResource::column('title_key'), Operator::Equals, $titleKey)
            ->fetchOneAs(NodeResource::class, $this->orm()->getMapperRegistry());

        if ($existing instanceof NodeResource) {
            $row = new NodeResource(
                id: $existing->id,
                kind: $existing->kind,
                title: $title !== '' ? $title : $existing->title,
                title_key: $existing->title_key,
                properties_json: $this->encode(array_merge($this->decode($existing->properties_json), $properties)),
                source: $existing->source !== '' ? $existing->source : $source,
                created_at: $existing->created_at,
                updated_at: $now,
            );
            $this->nodes()->update($row);

            return $this->toNode($row);
        }

        // Near-duplicate guard: different phrasings of the same thing reduce to
        // one sorted content-token set ("Semitexa documentation" == "documentation
        // for Semitexa"). Converge onto the existing node — its (earlier) title
        // stays canonical, new properties merge in. Bounded same-kind scan; the
        // graph is a personal world, not a warehouse.
        $tokenKey = TitleKey::tokenSet($title);
        $sameKind = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->limit(500)
            ->fetchAllAs(NodeResource::class, $this->orm()->getMapperRegistry());
        foreach ($sameKind as $candidate) {
            if (TitleKey::tokenSet($candidate->title) === $tokenKey) {
                $merged = new NodeResource(
                    id: $candidate->id,
                    kind: $candidate->kind,
                    title: $candidate->title,
                    title_key: $candidate->title_key,
                    properties_json: $this->encode(array_merge($this->decode($candidate->properties_json), $properties)),
                    source: $candidate->source !== '' ? $candidate->source : $source,
                    created_at: $candidate->created_at,
                    updated_at: $now,
                );
                $this->nodes()->update($merged);

                return $this->toNode($merged);
            }
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
        $this->nodes()->insert($row);

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

        $existing = $this->edges()->query()
            ->where(EdgeResource::column('from_id'), Operator::Equals, $fromId)
            ->where(EdgeResource::column('to_id'), Operator::Equals, $toId)
            ->where(EdgeResource::column('relation'), Operator::Equals, $relation)
            ->fetchOneAs(EdgeResource::class, $this->orm()->getMapperRegistry());

        if ($existing instanceof EdgeResource) {
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
        $this->edges()->insert($row);

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
        $edges = array_merge($this->edgesFrom($nodeId), $this->edgesTo($nodeId));

        $neighborIds = [];
        foreach ($edges as $edge) {
            $other = $edge->fromId === $nodeId ? $edge->toId : $edge->fromId;
            $neighborIds[$other] = true;
        }

        $neighbors = [];
        foreach (array_keys($neighborIds) as $id) {
            $neighbor = $this->node($id);
            if ($neighbor !== null) {
                $neighbors[] = $neighbor;
            }
        }

        return ['node' => $this->node($nodeId), 'edges' => $edges, 'neighbors' => $neighbors];
    }

    public function subgraph(string $nodeId, int $depth = 1): array
    {
        $depth = max(1, min(3, $depth));
        $center = $this->node($nodeId);
        if ($center === null) {
            return ['nodes' => [], 'edges' => []];
        }

        /** @var array<string, \Semitexa\Weave\Domain\Model\Node> $nodes */
        $nodes = [$nodeId => $center];
        /** @var array<string, \Semitexa\Weave\Domain\Model\Edge> $edges */
        $edges = [];
        $frontier = [$nodeId];

        for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
            $next = [];
            foreach ($frontier as $id) {
                foreach (array_merge($this->edgesFrom($id), $this->edgesTo($id)) as $edge) {
                    $edges[$edge->id] = $edge;
                    $other = $edge->fromId === $id ? $edge->toId : $edge->fromId;
                    if (!isset($nodes[$other])) {
                        $neighbor = $this->node($other);
                        if ($neighbor !== null) {
                            $nodes[$other] = $neighbor;
                            $next[] = $other;
                        }
                    }
                }
            }
            $frontier = $next;
        }

        // Rim cross-links: edges between two included nodes that BFS reached
        // through other paths — without them the local view loses real structure.
        foreach (array_keys($nodes) as $id) {
            foreach ($this->edgesFrom($id) as $edge) {
                if (isset($nodes[$edge->toId])) {
                    $edges[$edge->id] = $edge;
                }
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
