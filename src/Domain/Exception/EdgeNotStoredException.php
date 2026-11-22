<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * The storage refused an edge as a duplicate, yet the current tenant holds no
 * such edge.
 *
 * That happens only where `uniq_weave_edge_triple` still lacks `tenant_id`
 * (a schema not synced since the index gained it) and another tenant's row
 * holds the triple. The driver's own message quotes the colliding values, so
 * it stays in the chain for the log and never becomes this message.
 */
final class EdgeNotStoredException extends DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The Weave edge could not be stored.', 0, $previous);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::Conflict;
    }
}
