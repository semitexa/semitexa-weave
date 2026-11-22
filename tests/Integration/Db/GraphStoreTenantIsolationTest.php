<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Db\MySQL\Model\EdgeResource;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Exception\EdgeNotStoredException;
use Semitexa\Weave\Domain\Exception\UnknownNodeException;

/**
 * The Weave graph is #[TenantScoped] — per-tenant knowledge the WEAVER builds
 * from each tenant's own conversation. This pins that one tenant's graph can
 * never read, count, traverse, or converge onto another's, even when both hold
 * the SAME (kind, title) concept — the exact cross-tenant knowledge bleed the
 * global graph allowed.
 */
final class GraphStoreTenantIsolationTest extends TestCase
{
    private OrmManager $orm;
    private GraphTenantContextStore $ctx;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY, tenant_id TEXT, kind TEXT NOT NULL, title TEXT NOT NULL, title_key TEXT NOT NULL,
                token_key TEXT, ext_ref TEXT, properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_ext_ref ON weave_node (tenant_id, ext_ref)');
        $db->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY, tenant_id TEXT, from_id TEXT NOT NULL, to_id TEXT NOT NULL, relation TEXT NOT NULL,
                weight INTEGER NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        // The index EdgeResource declares, not a copy of it: these tests are
        // about what that declaration lets two tenants do.
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (' . implode(', ', $this->declaredEdgeTripleColumns()) . ')');

        $this->ctx = new GraphTenantContextStore();
    }

    private function store(): GraphStore
    {
        $store = (new GraphStore())->withTenantContextStore($this->ctx);
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }

    /** @return list<string> */
    private function declaredEdgeTripleColumns(): array
    {
        foreach ((new \ReflectionClass(EdgeResource::class))->getAttributes(Index::class) as $attribute) {
            $index = $attribute->newInstance();
            if ($index->name === 'uniq_weave_edge_triple') {
                $columns = [];
                foreach ($index->columns as $column) {
                    self::assertIsString($column);
                    $columns[] = $column;
                }

                return $columns;
            }
        }

        self::fail('EdgeResource declares no uniq_weave_edge_triple index.');
    }

    /** A row another tenant holds: what a planted or pre-fix edge looks like in storage. */
    private function plantEdgeRow(string $tenantId, string $fromId, string $toId, string $relation): void
    {
        $this->orm->getAdapter()->execute(
            "INSERT INTO weave_edge (id, tenant_id, from_id, to_id, relation, weight, source, created_at, updated_at)
             VALUES ('planted-" . $tenantId . "', '" . $tenantId . "', '" . $fromId . "', '" . $toId . "', '" . $relation . "', 100, '', '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
        );
    }

    #[Test]
    public function the_unique_edge_index_is_per_tenant(): void
    {
        self::assertSame(['tenant_id', 'from_id', 'to_id', 'relation'], $this->declaredEdgeTripleColumns());
    }

    #[Test]
    public function the_same_triple_is_an_independent_edge_per_tenant(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->getId();
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->getId();

        // Globex already holds the very triple Acme is about to write.
        $this->plantEdgeRow('globex', $a1, $a2, 'relates_to');

        $edge = $store->addEdge($a1, $a2, 'relates_to', 70, 'acme-src');

        self::assertSame(70, $edge->getWeight(), 'Acme wrote its own edge, it did not converge onto the Globex row.');
        self::assertSame(1, $store->counts()['edges'], 'Acme holds its own edge.');

        $this->ctx->switchTo('globex');
        self::assertSame(1, $store->counts()['edges'], 'Globex still holds only its own row.');
    }

    #[Test]
    public function an_edge_to_or_from_another_tenants_node_is_refused_like_an_unknown_node(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->getId();
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->getId();
        $store->addEdge($a1, $a2, 'relates_to');

        $this->ctx->switchTo('globex');
        $g = $store->upsertNode(NodeKind::Topic, 'G')->getId();

        $attempts = [
            'foreign from' => [$a1, $g, $a1],
            'foreign to' => [$g, $a1, $a1],
            'the triple Acme already holds' => [$a1, $a2, $a1],
            'unknown from' => ['no-such-node', $g, 'no-such-node'],
            'unknown to' => [$g, 'no-such-node', 'no-such-node'],
        ];
        foreach ($attempts as $case => [$from, $to, $refused]) {
            try {
                $store->addEdge($from, $to, 'relates_to');
                self::fail("{$case}: the edge was written.");
            } catch (UnknownNodeException $e) {
                // One class, one status, one message shape: nothing tells a
                // foreign node from one that was never there.
                self::assertSame("Weave node #{$refused} not found.", $e->getMessage(), $case);
                self::assertSame(404, $e->getStatusCode()->value, $case);
            }
        }

        self::assertSame(0, $store->counts()['edges'], 'Globex wrote nothing.');

        $this->ctx->switchTo('acme');
        self::assertSame(1, $store->counts()['edges'], 'Acme still holds exactly its own edge.');
    }

    #[Test]
    public function a_tenants_own_duplicate_still_converges_on_one_edge(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->getId();
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->getId();

        $first = $store->addEdge($a1, $a2, 'relates_to', 40, 'first');
        $second = $store->addEdge($a1, $a2, 'relates_to', 90, 'second');

        self::assertSame($first->getId(), $second->getId(), 'The repeat is the same edge.');
        self::assertSame(90, $second->getWeight(), 'The higher weight wins.');
        self::assertSame('first', $second->getSource(), 'The first source stays.');
        self::assertSame(1, $store->counts()['edges']);
    }

    #[Test]
    public function on_a_schema_not_yet_synced_a_foreign_duplicate_does_not_surface_the_drivers_message(): void
    {
        // An install that has not run orm:sync since the index gained tenant_id.
        $db = $this->orm->getAdapter();
        $db->execute('DROP INDEX uniq_weave_edge_triple');
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');

        $store = $this->store();
        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->getId();
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->getId();
        $this->plantEdgeRow('globex', $a1, $a2, 'relates_to');

        try {
            $store->addEdge($a1, $a2, 'relates_to');
            self::fail('The old index must still reject the triple.');
        } catch (EdgeNotStoredException $e) {
            self::assertSame('The Weave edge could not be stored.', $e->getMessage());
            self::assertInstanceOf(ConstraintViolationException::class, $e->getPrevious(), 'The cause stays in the chain for the log.');
        }
    }

    #[Test]
    public function the_same_concept_is_an_independent_node_per_tenant(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $acme = $store->upsertNode(NodeKind::Topic, 'Semitexa', ['secret' => 'acme'], 'acme-src');

        // Globex holds the same concept title — must NOT converge onto Acme's node.
        $this->ctx->switchTo('globex');
        $globex = $store->upsertNode(NodeKind::Topic, 'Semitexa', ['secret' => 'globex'], 'globex-src');

        self::assertNotSame($acme->getId(), $globex->getId(), 'The same (kind, title) is a distinct node per tenant.');
        self::assertSame(['secret' => 'globex'], $globex->getProperties(), 'No Acme properties bled in.');
        self::assertSame(1, $store->counts()['nodes'], 'Globex counts only its own node.');

        $this->ctx->switchTo('acme');
        self::assertSame(['secret' => 'acme'], $store->node($acme->getId())?->getProperties());
        self::assertSame(1, $store->counts()['nodes']);
    }

    #[Test]
    public function one_tenant_cannot_read_or_traverse_another_tenants_graph(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->getId();
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->getId();
        $store->addEdge($a1, $a2, 'relates_to');

        // Globex sees an empty graph, and Acme node ids resolve to nothing.
        $this->ctx->switchTo('globex');
        self::assertSame(0, $store->counts()['nodes']);
        self::assertSame(0, $store->counts()['edges']);
        self::assertNull($store->node($a1), 'A foreign node id must not resolve.');

        $hood = $store->neighborhood($a1);
        self::assertNull($hood['node'], 'neighborhood of a foreign node is empty.');
        self::assertCount(0, $hood['edges']);
    }
}

final class GraphTenantContextStore implements TenantContextStoreInterface
{
    private ?TenantContextInterface $context = null;

    public function switchTo(string $tenantId): void
    {
        $this->context = new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }

    public function get(): TenantContextInterface
    {
        return $this->context ?? throw new \LogicException('no context');
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->context;
    }

    public function set(TenantContextInterface $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
