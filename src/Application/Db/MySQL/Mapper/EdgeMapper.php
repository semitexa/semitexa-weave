<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Weave\Application\Db\MySQL\Model\EdgeResource;

/**
 * Self-mapping mapper for {@see EdgeResource} (see {@see NodeMapper}).
 */
#[AsMapper(
    resourceModel: EdgeResource::class,
    domainModel: EdgeResource::class,
)]
final class EdgeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof EdgeResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof EdgeResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
