<?php

declare(strict_types=1);

namespace Semitexa\Weave;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'weave.graph',
    summary: 'A knowledge and relationship graph data layer with typed nodes and edges.',
    useWhen: 'Relationships between records matter more than the containers they sit in, and the shape is not known upfront.',
    avoidWhen: 'The relationships are a fixed, known schema. Plain ORM relations are cheaper, queryable and easier to migrate.',
    replaces: [
        'nested folder hierarchies used to express what is related to what',
        'ad-hoc join tables added one at a time as new relationships appear',
    ],
)]
final class Capabilities
{
}
