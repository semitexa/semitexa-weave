<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Contract;

use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Edge;
use Semitexa\Weave\Domain\Model\Node;

/**
 * The Weave's persistence API — the single seam OS consumers (the weaver, views,
 * the intent loop) depend on. Nodes and edges are upserted idempotently so the
 * same entity/relationship woven twice does not duplicate.
 */
interface GraphStoreInterface
{
    /**
     * Create or fetch a node by (kind, normalised title). Merges the given
     * properties into an existing node rather than duplicating it.
     *
     * @param array<string, mixed> $properties
     */
    public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node;

    /**
     * Create or fetch an edge, deduped by (from, to, relation). A higher weight
     * on a repeat wins (an asserted edge upgrades an inferred one).
     */
    public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): Edge;

    /**
     * Update an existing node by id — rename (recomputes the dedup key) and/or
     * merge properties. Returns the updated node, or null if the id is unknown.
     *
     * @param array<string, mixed> $properties
     */
    public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node;

    public function node(string $id): ?Node;

    /**
     * @return list<Node>
     */
    public function nodesByKind(NodeKind $kind, int $limit = 0): array;

    /**
     * Case-insensitive title search across all kinds.
     *
     * @return list<Node>
     */
    public function search(string $term, int $limit = 20): array;

    /**
     * The node plus its incident edges and immediate neighbours — the unit a
     * contextual/local view renders (never the whole graph).
     *
     * @return array{node: ?Node, edges: list<Edge>, neighbors: list<Node>}
     */
    public function neighborhood(string $nodeId): array;

    /**
     * The whole graph (bounded) — nodes most-recently-touched first, plus their
     * edges — for a visualization to render. Bounded because a force layout
     * degrades past a few hundred nodes; contextual/ego views use neighborhood().
     *
     * @return array{nodes: list<Node>, edges: list<Edge>}
     */
    public function graph(int $limit = 500): array;

    public function removeNode(string $id): void;

    public function removeEdge(string $id): void;

    /**
     * @return array{nodes: int, edges: int}
     */
    public function counts(): array;
}
