<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for a directed, typed Weave edge. Deduped by
 * (from_id, to_id, relation) via the unique index; from_id/to_id are indexed
 * for neighbourhood queries. `weight` is a 0–100 confidence.
 */
#[FromTable(name: 'weave_edge')]
#[Index(columns: ['from_id', 'to_id', 'relation'], unique: true, name: 'uniq_weave_edge_triple')]
#[Index(columns: ['from_id'], name: 'idx_weave_edge_from')]
#[Index(columns: ['to_id'], name: 'idx_weave_edge_to')]
final readonly class EdgeResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $from_id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $to_id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $relation,

        #[Column(type: MySqlType::Int)]
        public int $weight,

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $source,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {
    }
}
