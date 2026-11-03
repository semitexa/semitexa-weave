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
 * Different phrasings of one title converge onto one node. The near-duplicate
 * check used to read up to 500 nodes of the kind, in no order, and tokenize
 * each title on every write: a graph past 500 nodes of a kind missed the
 * duplicates beyond them and grew a second node. It now looks the content-token
 * key up through an index.
 */
final class GraphStoreNearDuplicateTest extends TestCase
{
    private OrmManager $orm;

    private GraphStore $store;

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
                token_key TEXT,
                ext_ref TEXT,
                properties_json TEXT NOT NULL,
                source TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute('CREATE INDEX idx_weave_node_kind_token ON weave_node (tenant_id, kind, token_key)');
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_ext_ref ON weave_node (tenant_id, ext_ref)');

        $this->store = new GraphStore();
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($this->store, $this->orm);
    }

    #[Test]
    public function a_rephrased_title_converges_on_its_node_however_many_nodes_the_kind_has(): void
    {
        for ($i = 1; $i <= 600; $i++) {
            $this->store->upsertNode(NodeKind::Topic, "Release notes {$i} archive");
        }

        $node = $this->store->upsertNode(NodeKind::Topic, 'archive for release notes 600', ['seen' => true]);

        self::assertSame('Release notes 600 archive', $node->getTitle(), 'the earlier title stays canonical');
        self::assertSame(['seen' => true], $node->getProperties());
        self::assertSame(600, $this->nodeCount());
    }

    #[Test]
    public function a_node_is_found_by_its_token_key_in_one_indexed_read(): void
    {
        $this->store->upsertNode(NodeKind::Topic, 'Semitexa documentation');
        $this->orm->enableQueryLog();

        $this->store->upsertNode(NodeKind::Topic, 'documentation for Semitexa');
        $reads = array_values(array_filter(
            array_map(static fn (array $q): string => $q['sql'], $this->orm->drainQueryLog()),
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT *') && str_contains($sql, 'weave_node'),
        ));
        $this->orm->disableQueryLog();

        self::assertSame(1, $this->nodeCount());
        self::assertCount(2, $reads, implode("\n", $reads));
        self::assertStringContainsString('`title_key` =', $reads[0]);
        self::assertMatchesRegularExpression('/`token_key` = :\w+$/', $reads[1], 'the rows with that key, not a scan of the kind');
    }

    #[Test]
    public function a_node_written_before_the_token_key_existed_is_still_found(): void
    {
        $this->store->upsertNode(NodeKind::Topic, 'Semitexa documentation');
        // A row the schema sync gave a NULL column, before the backfill patch ran.
        $this->orm->getAdapter()->execute('UPDATE weave_node SET token_key = NULL');

        $this->store->upsertNode(NodeKind::Topic, 'documentation for Semitexa');

        self::assertSame(1, $this->nodeCount());
    }

    #[Test]
    public function a_title_never_converges_onto_a_node_that_mirrors_a_record(): void
    {
        $mirror = $this->store->upsertNodeByRef(NodeKind::Topic, 'regmus:page:12', 'Semitexa documentation');

        $node = $this->store->upsertNode(NodeKind::Topic, 'documentation for Semitexa', ['seen' => true]);

        self::assertNotSame($mirror->getId(), $node->getId());
        self::assertSame(2, $this->nodeCount());
    }

    #[Test]
    public function a_keyed_row_that_only_the_collation_calls_equal_does_not_hide_the_real_match(): void
    {
        // MySQL's _ci collation answers `cafe house` for `café house`; SQLite
        // compares bytes, so the stand-in is a row whose stored key disagrees
        // with its title, beside the real match written before the column.
        $this->store->upsertNode(NodeKind::Topic, 'Unrelated title');
        $this->store->upsertNode(NodeKind::Topic, 'Semitexa documentation');
        $db = $this->orm->getAdapter();
        $db->execute("UPDATE weave_node SET token_key = 'documentation semitexa' WHERE title = 'Unrelated title'");
        $db->execute("UPDATE weave_node SET token_key = NULL WHERE title = 'Semitexa documentation'");

        $node = $this->store->upsertNode(NodeKind::Topic, 'documentation for Semitexa');

        self::assertSame('Semitexa documentation', $node->getTitle());
        self::assertSame(2, $this->nodeCount());
    }

    private function nodeCount(): int
    {
        return (int) $this->orm->getAdapter()->execute('SELECT COUNT(*) AS n FROM weave_node')->rows[0]['n'];
    }
}
