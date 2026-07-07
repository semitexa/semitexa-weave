<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Db\MySQL\Model\NodeResource;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * upsertNode/addEdge read (exact-match + near-dup scan) then insert. Under
 * concurrency two coroutines can both pass those reads before either inserts,
 * so the second INSERT hits the natural-key UNIQUE index
 * (uniq_weave_node_kind_title / uniq_weave_edge_triple). The old code let that
 * violation propagate — a spurious failure that lost the losing upsert (and its
 * properties/weight). The fix catches it, re-reads the winner, and converges
 * by merging in the loser's properties / max-ing its weight.
 *
 * The race is reproduced deterministically against a real in-memory SQLite DB
 * (the UNIQUE index is genuine, so the INSERT genuinely throws): a GraphStore
 * subclass makes the FIRST exact-match read miss (the stale pre-insert read of
 * the concurrent coroutine) while the row physically exists — forcing the
 * insert path — then delegates to the real query for the post-collision
 * re-read so convergence can find the winner.
 */
final class GraphStoreUpsertRaceTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                kind TEXT NOT NULL,
                title TEXT NOT NULL,
                title_key TEXT NOT NULL,
                properties_json TEXT NOT NULL,
                source TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                from_id TEXT NOT NULL,
                to_id TEXT NOT NULL,
                relation TEXT NOT NULL,
                weight INTEGER NOT NULL,
                source TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');
    }

    #[Test]
    public function a_concurrent_duplicate_node_insert_converges_instead_of_throwing(): void
    {
        $seeder = $this->store(GraphStore::class);
        $seeder->upsertNode(NodeKind::Topic, 'Alpha', ['a' => 1], 'src-a');

        // The racer's first exact-match read misses (the concurrent coroutine's
        // stale pre-insert view), so it drives an INSERT that the UNIQUE index
        // rejects — then converges on the seeded winner.
        $racer = $this->store(RacingGraphStore::class);
        $node = $racer->upsertNode(NodeKind::Topic, 'Alpha', ['b' => 2], 'src-b');

        self::assertSame(['a' => 1, 'b' => 2], $node->properties, 'the loser must merge its properties into the winner, not be lost');
        self::assertSame(1, $seeder->counts()['nodes'], 'the UNIQUE index + convergence leave exactly one node');
        self::assertSame('Alpha', $node->title);
    }

    #[Test]
    public function a_concurrent_duplicate_edge_insert_converges_and_maxes_the_weight(): void
    {
        $a = $this->store(GraphStore::class)->upsertNode(NodeKind::Topic, 'A', [], 's')->id;
        $b = $this->store(GraphStore::class)->upsertNode(NodeKind::Topic, 'B', [], 's')->id;

        $seeder = $this->store(GraphStore::class);
        $seeder->addEdge($a, $b, 'relates_to', 40, 'src-a');

        $racer = $this->store(RacingGraphStore::class);
        $edge = $racer->addEdge($a, $b, 'relates_to', 90, 'src-b');

        self::assertSame(90, $edge->weight, 'the asserted (higher) weight must upgrade the inferred one, not be lost');
        self::assertSame(1, $seeder->counts()['edges'], 'exactly one edge survives the race');
    }

    #[Test]
    public function sequential_upserts_still_converge_on_one_node(): void
    {
        $store = $this->store(GraphStore::class);
        $store->upsertNode(NodeKind::Topic, 'Beta', ['x' => 1], 's');
        $node = $store->upsertNode(NodeKind::Topic, 'Beta', ['y' => 2], 's');

        self::assertSame(['x' => 1, 'y' => 2], $node->properties);
        self::assertSame(1, $store->counts()['nodes']);
    }

    /**
     * @template T of GraphStore
     * @param class-string<T> $class
     * @return T
     */
    private function store(string $class): GraphStore
    {
        $store = new $class();
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }
}

/**
 * Forces the first exact-match read of the target key to miss — the stale
 * pre-insert view of a losing concurrent coroutine — while the real row exists,
 * driving the INSERT → UNIQUE-violation → converge path. Later reads (the
 * post-collision re-read) delegate to the genuine query so convergence resolves
 * the winner.
 */
final class RacingGraphStore extends GraphStore
{
    private int $nodeReads = 0;
    private int $edgeReads = 0;

    protected function existingNodeByKey(NodeKind $kind, string $titleKey): ?NodeResource
    {
        if (++$this->nodeReads === 1) {
            return null;
        }

        return parent::existingNodeByKey($kind, $titleKey);
    }

    protected function findNearDuplicateNode(NodeKind $kind, string $title): ?NodeResource
    {
        return null; // skip the token-set convergence so the insert path is reached
    }

    protected function existingEdgeByTriple(string $fromId, string $toId, string $relation): ?\Semitexa\Weave\Application\Db\MySQL\Model\EdgeResource
    {
        if (++$this->edgeReads === 1) {
            return null;
        }

        return parent::existingEdgeByTriple($fromId, $toId, $relation);
    }
}
