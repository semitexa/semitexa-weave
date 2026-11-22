<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Exception;

use Semitexa\Core\Exception\NotFoundException;

/**
 * An edge names a node the current tenant's graph does not hold.
 *
 * One answer for a node that was never there and for a node that belongs to
 * another tenant: a caller holding a foreign node id must learn nothing from
 * the difference. Answered as 404.
 */
final class UnknownNodeException extends NotFoundException
{
    public function __construct(string $nodeId)
    {
        parent::__construct('Weave node', $nodeId);
    }

    /**
     * Refuse the first of $nodeIds that is not among the nodes read through
     * the current tenant's scope.
     *
     * @param array<string, mixed> $known nodes keyed by id
     */
    public static function unlessKnown(array $known, string ...$nodeIds): void
    {
        foreach ($nodeIds as $nodeId) {
            if (!isset($known[$nodeId])) {
                throw new self($nodeId);
            }
        }
    }
}
