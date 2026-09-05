<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\Application\Service\OrmBackedStore;
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
 * doesn't duplicate. The store speaks {@see Node}/{@see Edge} throughout; the
 * row shape — the JSON property bag, the string kind, the derived `title_key` —
 * lives behind {@see \Semitexa\Weave\Application\Db\MySQL\Mapper\NodeMapper}.
 *
 * OrmManager wiring (injection + lazy fallback + memoized repositories) comes
 * from {@see OrmBackedStore}.
 */
#[SatisfiesServiceContract(of: GraphStoreInterface::class)]
class GraphStore implements GraphStoreInterface
{
    use OrmBackedStore;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Ambient-tenant seam (coroutine-local), resolved AT CALL TIME. The graph
     * is per-tenant knowledge: nodes()/edges() bind this so every read is
     * filtered and every write is stamped. Mirrors CalendarEventDbRepository.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node
    {
        $title = trim($title);
        $titleKey = $this->titleKey($title);
        $now = new \DateTimeImmutable();

        $existing = $this->existingNodeByKey($kind, $titleKey);
        if ($existing instanceof Node) {
            return $this->mergeIntoNode($existing, $title !== '' ? $title : $existing->getTitle(), $properties, $source, $now);
        }

        // Near-duplicate guard: different phrasings of the same thing reduce to
        // one sorted content-token set ("Semitexa documentation" == "documentation
        // for Semitexa"). Converge onto the existing node — its (earlier) title
        // stays canonical, new properties merge in. Bounded same-kind scan; the
        // graph is a personal world, not a warehouse.
        $nearDup = $this->findNearDuplicateNode($kind, $title);
        if ($nearDup instanceof Node) {
            return $this->mergeIntoNode($nearDup, $nearDup->getTitle(), $properties, $source, $now);
        }

        $node = new Node(
            id: Uuid7::generate(),
            kind: $kind,
            title: $title,
            properties: $properties,
            source: $source,
            createdAt: $now,
            updatedAt: $now,
            tenantId: $this->currentTenantId(),
        );

        try {
            $this->nodes()->insert($node);

            return $node;
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
            if ($winner instanceof Node) {
                return $this->mergeIntoNode($winner, $title !== '' ? $title : $winner->getTitle(), $properties, $source, $now);
            }

            throw $e;
        }
    }

    /** Exact-match lookup by (kind, normalised title). Also the post-collision re-read. */
    protected function existingNodeByKey(NodeKind $kind, string $titleKey): ?Node
    {
        $node = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->where(NodeResource::column('title_key'), Operator::Equals, $titleKey)
            ->fetchOneAs(Node::class, $this->mapperRegistry());

        return $node instanceof Node ? $node : null;
    }

    /** Bounded same-kind scan converging different phrasings onto one node via the content-token set. */
    protected function findNearDuplicateNode(NodeKind $kind, string $title): ?Node
    {
        $tokenKey = TitleKey::tokenSet($title);
        /** @var list<Node> $sameKind */
        $sameKind = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->limit(500)
            ->fetchAllAs(Node::class, $this->mapperRegistry());
        foreach ($sameKind as $candidate) {
            if (TitleKey::tokenSet($candidate->getTitle()) === $tokenKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Merge properties/source into an existing node (last-title-wins per the caller's resolution) and persist.
     *
     * @param array<string, mixed> $properties
     */
    private function mergeIntoNode(Node $existing, string $title, array $properties, string $source, \DateTimeImmutable $now): Node
    {
        $merged = new Node(
            id: $existing->getId(),
            kind: $existing->getKind(),
            title: $title,
            properties: array_merge($existing->getProperties(), $properties),
            source: $existing->getSource() !== '' ? $existing->getSource() : $source,
            createdAt: $existing->getCreatedAt(),
            updatedAt: $now,
            ref: $existing->getRef(),
            tenantId: $this->currentTenantId(),
        );
        $this->nodes()->update($merged);

        return $merged;
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
        $keep = $this->nodeById($keepId);
        $drop = $this->nodeById($dropId);
        if (!$keep instanceof Node || !$drop instanceof Node) {
            return;
        }

        foreach (array_merge($this->edgesFrom($dropId), $this->edgesTo($dropId)) as $edge) {
            $newFrom = $edge->getFromId() === $dropId ? $keepId : $edge->getFromId();
            $newTo = $edge->getToId() === $dropId ? $keepId : $edge->getToId();
            $this->removeEdge($edge->getId());
            if ($newFrom === $newTo) {
                continue; // a self-loop carries no information
            }
            // addEdge() dedups on (from, to, relation), so collisions collapse.
            $this->addEdge($newFrom, $newTo, $edge->getRelation(), $edge->getWeight(), $edge->getSource());
        }

        $now = new \DateTimeImmutable();
        $this->nodes()->update(new Node(
            id: $keep->getId(),
            kind: $keep->getKind(),
            title: $keep->getTitle(),
            properties: array_merge($drop->getProperties(), $keep->getProperties()),
            source: $keep->getSource() !== '' ? $keep->getSource() : $drop->getSource(),
            createdAt: $keep->getCreatedAt(),
            updatedAt: $now,
            ref: $keep->getRef(),
            tenantId: $this->currentTenantId(),
        ));
        $this->nodes()->delete($drop);
    }

    public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): Edge
    {
        $relation = Relation::normalise($relation);
        $weight = max(0, min(100, $weight));
        $now = new \DateTimeImmutable();

        $existing = $this->existingEdgeByTriple($fromId, $toId, $relation);
        if ($existing instanceof Edge) {
            return $this->mergeIntoEdge($existing, $fromId, $toId, $relation, $weight, $source, $now);
        }

        $edge = new Edge(
            id: Uuid7::generate(),
            fromId: $fromId,
            toId: $toId,
            relation: $relation,
            weight: $weight,
            source: $source,
            createdAt: $now,
            updatedAt: $now,
            tenantId: $this->currentTenantId(),
        );

        try {
            $this->edges()->insert($edge);

            return $edge;
        } catch (\Throwable $e) {
            // Lost the first-write race: a concurrent addEdge of the same
            // (from_id, to_id, relation) inserted first and the unique index
            // uniq_weave_edge_triple rejected ours. Converge onto the winner
            // and fold in our weight (max) instead of failing and losing the
            // assertion. No row present ⇒ a real error, not the race: rethrow.
            $winner = $this->existingEdgeByTriple($fromId, $toId, $relation);
            if ($winner instanceof Edge) {
                return $this->mergeIntoEdge($winner, $fromId, $toId, $relation, $weight, $source, $now);
            }

            throw $e;
        }
    }

    /** Exact-match lookup by (from_id, to_id, relation). Also the post-collision re-read. */
    protected function existingEdgeByTriple(string $fromId, string $toId, string $relation): ?Edge
    {
        $edge = $this->edges()->query()
            ->where(EdgeResource::column('from_id'), Operator::Equals, $fromId)
            ->where(EdgeResource::column('to_id'), Operator::Equals, $toId)
            ->where(EdgeResource::column('relation'), Operator::Equals, $relation)
            ->fetchOneAs(Edge::class, $this->mapperRegistry());

        return $edge instanceof Edge ? $edge : null;
    }

    /** Fold a new assertion into an existing edge: weight upgrades (max), source fills if empty, then persist. */
    private function mergeIntoEdge(Edge $existing, string $fromId, string $toId, string $relation, int $weight, string $source, \DateTimeImmutable $now): Edge
    {
        $merged = new Edge(
            id: $existing->getId(),
            fromId: $fromId,
            toId: $toId,
            relation: $relation,
            weight: max($weight, $existing->getWeight()), // an asserted edge upgrades an inferred one
            source: $existing->getSource() !== '' ? $existing->getSource() : $source,
            createdAt: $existing->getCreatedAt(),
            updatedAt: $now,
            tenantId: $this->currentTenantId(),
        );
        $this->edges()->update($merged);

        return $merged;
    }

    public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node
    {
        $existing = $this->nodeById($id);
        if (!$existing instanceof Node) {
            return null;
        }

        $newTitle = ($title !== null && trim($title) !== '') ? trim($title) : $existing->getTitle();
        $updated = new Node(
            id: $existing->getId(),
            kind: $existing->getKind(),
            title: $newTitle,
            properties: array_merge($existing->getProperties(), $properties),
            source: $existing->getSource(),
            createdAt: $existing->getCreatedAt(),
            updatedAt: new \DateTimeImmutable(),
            ref: $existing->getRef(),
            tenantId: $this->currentTenantId(),
        );
        $this->nodes()->update($updated);

        return $updated;
    }

    public function node(string $id): ?Node
    {
        return $this->nodeById($id);
    }

    /** One node by id, through the tenant-scoped repository. */
    private function nodeById(string $id): ?Node
    {
        $node = $this->nodes()->query()
            ->where(NodeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(Node::class, $this->mapperRegistry());

        return $node instanceof Node ? $node : null;
    }

    public function nodesByKind(NodeKind $kind, int $limit = 0): array
    {
        $query = $this->nodes()->query()
            ->where(NodeResource::column('kind'), Operator::Equals, $kind->value)
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc);
        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var list<Node> $nodes */
        $nodes = $query->fetchAllAs(Node::class, $this->mapperRegistry());

        return $nodes;
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        /** @var list<Node> $hits */
        $hits = $this->nodes()->query()
            ->whereLike(NodeResource::column('title'), '%' . $term . '%')
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc)
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry());

        return $hits;
    }

    public function neighborhood(string $nodeId): array
    {
        // 2 edge queries + 1 node query, regardless of degree — was O(neighbours)
        // (one node() SELECT per neighbour plus one for the centre).
        $edges = $this->edgesTouching([$nodeId]);

        $neighborIds = [];
        foreach ($edges as $edge) {
            $other = $edge->getFromId() === $nodeId ? $edge->getToId() : $edge->getFromId();
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
                foreach ([$edge->getFromId(), $edge->getToId()] as $end) {
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
            if (isset($nodes[$edge->getFromId()]) && isset($nodes[$edge->getToId()])) {
                $edges[$edge->getId()] = $edge;
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    /**
     * @param list<NodeKind>|null $kinds
     * @return array{nodes: list<Node>, edges: list<Edge>}
     */
    public function graph(int $limit = 500, ?array $kinds = null): array
    {
        $limit = max(1, $limit);
        $nodeQuery = $this->nodes()->query();

        if ($kinds !== null) {
            $nodeQuery->whereIn(
                NodeResource::column('kind'),
                array_map(static fn (NodeKind $kind): string => $kind->value, $kinds),
            );
        }

        /** @var list<Node> $nodes */
        $nodes = $nodeQuery
            ->orderBy(NodeResource::column('updated_at'), Direction::Desc)
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry());
        /** @var list<Edge> $edges */
        $edges = $this->edges()->query()
            ->limit($limit * 8)
            ->fetchAllAs(Edge::class, $this->mapperRegistry());

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Upsert a node whose identity is a record outside the graph.
     *
     * {@see upsertNode()} identifies by title, which is right for a graph a
     * language model infers from conversation and wrong for one that mirrors
     * records: renaming a page would mint a second node, and the near-duplicate
     * guard could quietly fuse two genuinely different places whose titles
     * happen to share their content words. Here the ref is the identity and the
     * title is just data that can change freely.
     */
    public function upsertNodeByRef(
        NodeKind $kind,
        string $ref,
        string $title,
        array $properties = [],
        string $source = '',
    ): Node {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('A referenced node needs a non-empty ref.');
        }

        $title = trim($title);
        $now = new \DateTimeImmutable();
        $existing = $this->rowByRef($ref);

        if ($existing instanceof Node) {
            $updated = new Node(
                id: $existing->getId(),
                kind: $kind,
                title: $title !== '' ? $title : $existing->getTitle(),
                properties: array_merge($existing->getProperties(), $properties),
                source: $source !== '' ? $source : $existing->getSource(),
                createdAt: $existing->getCreatedAt(),
                updatedAt: $now,
                ref: $ref,
                tenantId: $this->currentTenantId(),
            );
            $this->nodes()->update($updated);

            return $updated;
        }

        $node = new Node(
            id: Uuid7::generate(),
            kind: $kind,
            title: $title,
            properties: $properties,
            source: $source,
            createdAt: $now,
            updatedAt: $now,
            ref: $ref,
            tenantId: $this->currentTenantId(),
        );
        $this->nodes()->insert($node);

        return $node;
    }

    /** The node mirroring this record, or null. */
    public function nodeByRef(string $ref): ?Node
    {
        return $this->rowByRef(trim($ref));
    }

    private function rowByRef(string $ref): ?Node
    {
        if ($ref === '') {
            return null;
        }

        $node = $this->nodes()->query()
            ->where(NodeResource::column('ext_ref'), Operator::Equals, $ref)
            ->fetchOneAs(Node::class, $this->mapperRegistry());

        return $node instanceof Node ? $node : null;
    }

    public function removeNode(string $id): void
    {
        foreach (array_merge($this->edgesFrom($id), $this->edgesTo($id)) as $edge) {
            $this->removeEdge($edge->getId());
        }
        $node = $this->nodeById($id);
        if ($node instanceof Node) {
            $this->nodes()->delete($node);
        }
    }

    public function removeEdge(string $id): void
    {
        $edge = $this->edges()->query()
            ->where(EdgeResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(Edge::class, $this->mapperRegistry());
        if ($edge instanceof Edge) {
            $this->edges()->delete($edge);
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
        /** @var list<Node> $nodes */
        $nodes = $this->nodes()->query()
            ->whereIn(NodeResource::column('id'), $ids)
            ->fetchAllAs(Node::class, $this->mapperRegistry());

        $map = [];
        foreach ($nodes as $node) {
            $map[$node->getId()] = $node;
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
        /** @var list<Edge> $from */
        $from = $this->edges()->query()
            ->whereIn(EdgeResource::column('from_id'), $ids)
            ->fetchAllAs(Edge::class, $this->mapperRegistry());
        /** @var list<Edge> $to */
        $to = $this->edges()->query()
            ->whereIn(EdgeResource::column('to_id'), $ids)
            ->fetchAllAs(Edge::class, $this->mapperRegistry());

        $byId = [];
        foreach (array_merge($from, $to) as $edge) {
            $byId[$edge->getId()] = $edge;
        }

        return array_values($byId);
    }

    /** @return list<Edge> */
    private function edgesFrom(string $nodeId): array
    {
        /** @var list<Edge> $edges */
        $edges = $this->edges()->query()
            ->where(EdgeResource::column('from_id'), Operator::Equals, $nodeId)
            ->fetchAllAs(Edge::class, $this->mapperRegistry());

        return $edges;
    }

    /** @return list<Edge> */
    private function edgesTo(string $nodeId): array
    {
        /** @var list<Edge> $edges */
        $edges = $this->edges()->query()
            ->where(EdgeResource::column('to_id'), Operator::Equals, $nodeId)
            ->fetchAllAs(Edge::class, $this->mapperRegistry());

        return $edges;
    }

    private function titleKey(string $title): string
    {
        return TitleKey::exact($title);
    }

    private function nodes(): DomainRepository
    {
        return $this->domainRepository(NodeResource::class, Node::class)->forTenant($this->currentTenantId());
    }

    private function edges(): DomainRepository
    {
        return $this->domainRepository(EdgeResource::class, Edge::class)->forTenant($this->currentTenantId());
    }

    /**
     * Current tenant id, or the 'default' sentinel — never null, so the
     * fail-closed tenant filter always binds a concrete value.
     */
    private function currentTenantId(): string
    {
        return TenantContextAccess::tenantIdOrDefault($this->tenantContextStore()->tryGet());
    }

    /**
     * The ambient tenant store, injected or built.
     *
     * The store keeps the context in a coroutine-local, so an instance built
     * here reads exactly what an injected one would. Returning null when the
     * property is unset — which is what this did — silently answered 'default'
     * for every caller that constructs the store bare, and under a tenant
     * fan-out that is one tenant's graph handed to the next.
     */
    private function tenantContextStore(): TenantContextStoreInterface
    {
        return $this->tenantContextStore ??= new DefaultTenantContextStore();
    }
}
