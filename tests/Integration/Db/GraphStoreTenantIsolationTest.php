<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;

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
                properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY, tenant_id TEXT, from_id TEXT NOT NULL, to_id TEXT NOT NULL, relation TEXT NOT NULL,
                weight INTEGER NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');

        $this->ctx = new GraphTenantContextStore();
    }

    private function store(): GraphStore
    {
        $store = (new GraphStore())->withTenantContextStore($this->ctx);
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
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

        self::assertNotSame($acme->id, $globex->id, 'The same (kind, title) is a distinct node per tenant.');
        self::assertSame(['secret' => 'globex'], $globex->properties, 'No Acme properties bled in.');
        self::assertSame(1, $store->counts()['nodes'], 'Globex counts only its own node.');

        $this->ctx->switchTo('acme');
        self::assertSame(['secret' => 'acme'], $store->node($acme->id)?->properties);
        self::assertSame(1, $store->counts()['nodes']);
    }

    #[Test]
    public function one_tenant_cannot_read_or_traverse_another_tenants_graph(): void
    {
        $store = $this->store();

        $this->ctx->switchTo('acme');
        $a1 = $store->upsertNode(NodeKind::Topic, 'A1')->id;
        $a2 = $store->upsertNode(NodeKind::Topic, 'A2')->id;
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
