<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for a Weave node. `title_key` is the normalised title used for
 * idempotent upsert (unique per kind); `ext_ref` is the identity of a node that
 * MIRRORS something outside the graph (a page row, a media asset), where the
 * title is data rather than identity and renaming must not mint a second node;
 * `properties_json` holds the open per-kind property bag (the store
 * json-encodes/decodes it). `final readonly`
 * with constructor-promoted `#[Column]` per the ORM resource contract; the UUID
 * id is supplied by the store (manual PK strategy).
 */
#[FromTable(name: 'weave_node')]
#[Index(columns: ['tenant_id', 'kind', 'title_key'], unique: true, name: 'uniq_weave_node_kind_title')]
#[Index(columns: ['kind'], name: 'idx_weave_node_kind')]
#[Index(columns: ['tenant_id', 'ext_ref'], unique: true, name: 'uniq_weave_node_ext_ref')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class NodeResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        /** Owning tenant; the ORM gate filters every graph read by this. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $kind,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title_key,

        /** Stable reference to the record this node mirrors, e.g. 'regmus:page:12'. */
        #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $ext_ref,

        #[Column(type: MySqlType::LongText)]
        public string $properties_json,

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $source,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {
    }
}
