<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * A node that mirrors a record is identified by the record, not by its title.
 *
 * upsertNode() was built for a graph a language model infers from conversation,
 * where the title IS the identity and near-duplicate phrasings should converge.
 * Applied to site structure that behaviour is destructive twice over: renaming
 * a page mints a second node, and two genuinely different places whose titles
 * share their content words get silently fused into one.
 */
final class GraphStoreRefIdentityTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY, tenant_id TEXT, kind TEXT NOT NULL, title TEXT NOT NULL, title_key TEXT NOT NULL,
                ext_ref TEXT, properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
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
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');
    }

    private function store(): GraphStore
    {
        $store = new GraphStore();
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }

    #[Test]
    public function renaming_the_record_keeps_one_node(): void
    {
        $store = $this->store();

        $first = $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:7', 'Контакти', [], 'cms:map');
        $renamed = $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:7', 'Як нас знайти', [], 'cms:map');

        self::assertSame($first->id, $renamed->id);
        self::assertSame('Як нас знайти', $renamed->title);
        self::assertCount(1, $store->nodesByKind(NodeKind::Page));
    }

    #[Test]
    public function two_records_stay_two_nodes_even_when_their_titles_would_converge(): void
    {
        $store = $this->store();

        $a = $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:11', 'Тимчасові виставки', [], 'cms:map');
        $b = $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:12', 'Виставки тимчасові', [], 'cms:map');

        self::assertNotSame($a->id, $b->id);
        self::assertCount(2, $store->nodesByKind(NodeKind::Page));
    }

    #[Test]
    public function the_record_can_be_found_by_its_reference(): void
    {
        $store = $this->store();
        $store->upsertNodeByRef(NodeKind::Collection, 'regmus:events', 'Події', ['count' => 85], 'cms:map');

        $found = $store->nodeByRef('regmus:events');

        self::assertNotNull($found);
        self::assertSame('Події', $found->title);
        self::assertSame('regmus:events', $found->ref);
        self::assertSame(85, $found->properties['count'] ?? null);
        self::assertNull($store->nodeByRef('regmus:nothing'));
    }

    #[Test]
    public function properties_merge_across_runs_so_a_rebuild_does_not_drop_what_the_operator_set(): void
    {
        $store = $this->store();
        $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:1', 'Головна', ['pinned' => true], 'operator');

        $rebuilt = $store->upsertNodeByRef(NodeKind::Page, 'regmus:page:1', 'Головна', ['sef' => 'home-page-1'], 'cms:map');

        self::assertTrue($rebuilt->properties['pinned'] ?? false);
        self::assertSame('home-page-1', $rebuilt->properties['sef'] ?? null);
    }

    #[Test]
    public function a_node_without_a_reference_is_refused_rather_than_stored_anonymously(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->upsertNodeByRef(NodeKind::Page, '   ', 'Somewhere');
    }
}
