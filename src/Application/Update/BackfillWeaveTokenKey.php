<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Weave\Domain\Model\TitleKey;

/**
 * Fill weave_node.token_key for the nodes written before the column existed.
 * The near-duplicate lookup reads it through an index; a NULL row is still
 * compared the old way, so until this runs nothing is lost, only slower.
 * The key is TitleKey::tokenSet(), computed in PHP, so the rows are read and
 * written back in batches. Idempotent: only NULL rows.
 */
#[AsDataPatch(
    id: 'backfill-weave-token-key',
    module: 'semitexa/weave',
    phase: UpdatePhase::Post,
    requiresColumns: ['weave_node' => ['token_key']],
    description: 'Give every existing Weave node its near-duplicate key.',
)]
final class BackfillWeaveTokenKey implements DataPatchInterface
{
    private const BATCH = 500;

    public function apply(DataPatchContext $ctx): void
    {
        // Walk by id, not by "still NULL": a row the update could not change
        // would otherwise be read again forever.
        $after = '';
        do {
            $rows = $ctx->execute(
                'SELECT `id`, `title` FROM `weave_node` WHERE `token_key` IS NULL AND `id` > :after ORDER BY `id` LIMIT ' . self::BATCH,
                ['after' => $after],
            )->rows;
            foreach ($rows as $row) {
                $after = (string) $row['id'];
                $ctx->execute(
                    'UPDATE `weave_node` SET `token_key` = :token_key WHERE `id` = :id',
                    ['token_key' => TitleKey::tokenSet((string) $row['title']), 'id' => $after],
                );
            }
        } while (count($rows) === self::BATCH);
    }
}
