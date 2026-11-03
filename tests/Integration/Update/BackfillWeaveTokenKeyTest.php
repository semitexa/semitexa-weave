<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Integration\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Weave\Application\Update\BackfillWeaveTokenKey;

final class BackfillWeaveTokenKeyTest extends TestCase
{
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $this->db = (new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true)))->getAdapter();
        $this->db->execute('CREATE TABLE weave_node (id TEXT PRIMARY KEY, title TEXT NOT NULL, token_key TEXT)');
    }

    #[Test]
    public function every_null_key_is_filled_across_batches_and_a_set_key_is_left_alone(): void
    {
        for ($i = 1; $i <= 1203; $i++) {
            $this->db->execute('INSERT INTO weave_node (id, title) VALUES (:id, :title)', ['id' => sprintf('n%05d', $i), 'title' => "Notes for Release {$i}"]);
        }
        $this->db->execute("INSERT INTO weave_node (id, title, token_key) VALUES ('kept', 'Anything', 'already set')");

        (new BackfillWeaveTokenKey())->apply(new DataPatchContext($this->db));

        self::assertSame(0, $this->rowsWhere('token_key IS NULL'));
        self::assertSame('1203 notes release', $this->keyOf('n01203'));
        self::assertSame('already set', $this->keyOf('kept'));
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->db->execute("INSERT INTO weave_node (id, title) VALUES ('a', 'The Semitexa documentation')");
        $patch = new BackfillWeaveTokenKey();
        $patch->apply(new DataPatchContext($this->db));
        $patch->apply(new DataPatchContext($this->db));

        self::assertSame('documentation semitexa', $this->keyOf('a'));
    }

    private function rowsWhere(string $where): int
    {
        return (int) $this->db->execute("SELECT COUNT(*) AS n FROM weave_node WHERE {$where}")->rows[0]['n'];
    }

    private function keyOf(string $id): ?string
    {
        $value = $this->db->execute('SELECT token_key FROM weave_node WHERE id = :id', ['id' => $id])->rows[0]['token_key'];

        return $value === null ? null : (string) $value;
    }
}
