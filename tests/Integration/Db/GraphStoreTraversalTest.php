<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * neighborhood() and subgraph() used to issue one SELECT per neighbour/visited
 * node (edgesFrom + edgesTo + node), an O(N) round-trip cliff on the request
 * hot path (Workspace focus render / recall skill). They now batch node and
 * edge loads (WHERE id IN / from_id IN / to_id IN), so the query count is a
 * small constant independent of the node's degree.
 *
 * Both the result shape (behaviour parity) and the bounded, N-independent query
 * count are asserted against a real in-memory SQLite graph.
 */
final class GraphStoreTraversalTest extends TestCase
{
    private OrmManager $orm;
    private CountingAdapter $counter;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $real = $this->orm->getAdapter();
        $real->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY, kind TEXT NOT NULL, title TEXT NOT NULL, title_key TEXT NOT NULL,
                properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $real->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (kind, title_key)');
        $real->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY, from_id TEXT NOT NULL, to_id TEXT NOT NULL, relation TEXT NOT NULL,
                weight INTEGER NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $real->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');

        // Count queries only from here on — the counter wraps the real adapter.
        $this->counter = new CountingAdapter($real);
        (new \ReflectionProperty(OrmManager::class, 'adapter'))->setValue($this->orm, $this->counter);
    }

    #[Test]
    public function neighborhood_returns_the_centre_its_edges_and_existing_neighbours(): void
    {
        $store = $this->store();
        $c = $store->upsertNode(NodeKind::Topic, 'Centre')->id;
        $n1 = $store->upsertNode(NodeKind::Topic, 'N1')->id;
        $n2 = $store->upsertNode(NodeKind::Topic, 'N2')->id;
        $store->addEdge($c, $n1, 'relates_to');
        $store->addEdge($n2, $c, 'relates_to'); // incoming edge — neighbour via to→from
        $store->addEdge($c, 'ghost-missing', 'relates_to'); // orphan: target node absent

        $this->counter->reset();
        $hood = $store->neighborhood($c);
        $queries = $this->counter->count;

        self::assertSame('Centre', $hood['node']?->title);
        self::assertCount(3, $hood['edges'], 'all edges touching the centre, incl. the orphan');
        $neighborTitles = array_map(static fn($n) => $n->title, $hood['neighbors']);
        sort($neighborTitles);
        self::assertSame(['N1', 'N2'], $neighborTitles, 'existing neighbours only — the ghost is dropped');

        self::assertLessThanOrEqual(3, $queries, 'neighborhood must be a small constant number of queries');
    }

    #[Test]
    public function subgraph_walks_to_depth_and_includes_internal_cross_links(): void
    {
        $store = $this->store();
        $c = $store->upsertNode(NodeKind::Topic, 'C')->id;
        $a = $store->upsertNode(NodeKind::Topic, 'A')->id;
        $b = $store->upsertNode(NodeKind::Topic, 'B')->id;
        $far = $store->upsertNode(NodeKind::Topic, 'Far')->id; // 2 hops from C, via A
        $store->addEdge($c, $a, 'relates_to');
        $store->addEdge($c, $b, 'relates_to');
        $store->addEdge($a, $b, 'relates_to'); // cross-link between two depth-1 nodes
        $store->addEdge($a, $far, 'relates_to');

        $depth1 = $store->subgraph($c, 1);
        $titles1 = array_map(static fn($n) => $n->title, $depth1['nodes']);
        sort($titles1);
        self::assertSame(['A', 'B', 'C'], $titles1, 'depth 1 = centre + direct neighbours');
        // Internal edges among {C,A,B}: C-A, C-B, and the A-B cross-link.
        self::assertCount(3, $depth1['edges'], 'includes the A–B cross-link, excludes A→Far (Far not in set)');

        $depth2 = $store->subgraph($c, 2);
        $titles2 = array_map(static fn($n) => $n->title, $depth2['nodes']);
        sort($titles2);
        self::assertSame(['A', 'B', 'C', 'Far'], $titles2, 'depth 2 pulls in Far');
        self::assertCount(4, $depth2['edges'], 'now A→Far is internal too');
    }

    #[Test]
    public function subgraph_query_count_does_not_grow_with_the_node_degree(): void
    {
        $store = $this->store();
        $hub = $store->upsertNode(NodeKind::Topic, 'Hub')->id;
        for ($i = 0; $i < 5; $i++) {
            $store->addEdge($hub, $store->upsertNode(NodeKind::Topic, "leaf-$i")->id, 'relates_to');
        }
        $this->counter->reset();
        $store->subgraph($hub, 1);
        $withFive = $this->counter->count;

        $hub2 = $store->upsertNode(NodeKind::Topic, 'Hub2')->id;
        for ($i = 0; $i < 20; $i++) {
            $store->addEdge($hub2, $store->upsertNode(NodeKind::Topic, "leaf2-$i")->id, 'relates_to');
        }
        $this->counter->reset();
        $store->subgraph($hub2, 1);
        $withTwenty = $this->counter->count;

        self::assertSame($withFive, $withTwenty, 'batched traversal is O(hops), not O(degree)');
        self::assertLessThanOrEqual(6, $withTwenty, 'depth-1 subgraph is a handful of queries regardless of degree');
    }

    private function store(): GraphStore
    {
        $store = new GraphStore();
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }
}

/** Wraps a real adapter and counts execute()/query() round-trips. */
final class CountingAdapter implements DatabaseAdapterInterface
{
    public int $count = 0;

    public function __construct(private readonly DatabaseAdapterInterface $inner) {}

    public function reset(): void
    {
        $this->count = 0;
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->count++;
        return $this->inner->execute($sql, $params);
    }

    public function query(string $sql): QueryResult
    {
        $this->count++;
        return $this->inner->query($sql);
    }

    public function supports(ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}
