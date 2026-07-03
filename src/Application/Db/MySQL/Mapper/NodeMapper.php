<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Weave\Application\Db\MySQL\Model\NodeResource;

/**
 * Self-mapping mapper for {@see NodeResource} — the row lines up 1:1 with the
 * store's needs (the store wraps it into the {@see \Semitexa\Weave\Domain\Model\Node}
 * value object itself), so both directions are clone-passthroughs (same
 * trivial-shape convention as SettingMapper).
 */
#[AsMapper(
    resourceModel: NodeResource::class,
    domainModel: NodeResource::class,
)]
final class NodeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof NodeResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof NodeResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
