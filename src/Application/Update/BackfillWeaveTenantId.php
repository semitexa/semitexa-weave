<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Backfill weave_node.tenant_id / weave_edge.tenant_id after the graph became
 * #[TenantScoped]. Pre-tenancy rows are NULL; the scoped GraphStore reads under
 * forTenant('default') (WHERE tenant_id = 'default'), so without this patch the
 * whole existing knowledge graph becomes invisible after the schema sync.
 * 'default' is the no-context sentinel, so the existing graph stays with the
 * default tenant. Idempotent: only NULL rows on each table.
 */
#[AsDataPatch(
    id: 'backfill-weave-tenant-id',
    module: 'semitexa/weave',
    phase: UpdatePhase::Post,
    requiresColumns: ['weave_node' => ['tenant_id'], 'weave_edge' => ['tenant_id']],
    description: 'Assign the existing Weave graph to the default tenant.',
)]
final class BackfillWeaveTenantId implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        $ctx->execute("UPDATE `weave_node` SET `tenant_id` = 'default' WHERE `tenant_id` IS NULL");
        $ctx->execute("UPDATE `weave_edge` SET `tenant_id` = 'default' WHERE `tenant_id` IS NULL");
    }
}
