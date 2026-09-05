<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Weave\Application\Db\MySQL\Model\EdgeResource;
use Semitexa\Weave\Domain\Model\Edge;

/** The bridge between the `weave_edge` row and one directed edge of the graph. */
#[AsMapper(resourceModel: EdgeResource::class, domainModel: Edge::class)]
final class EdgeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof EdgeResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return new Edge(
            id: $resourceModel->id,
            fromId: $resourceModel->from_id,
            toId: $resourceModel->to_id,
            relation: $resourceModel->relation,
            weight: $resourceModel->weight,
            source: $resourceModel->source,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
            tenantId: $resourceModel->tenant_id,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof Edge || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new EdgeResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            from_id: $domainModel->getFromId(),
            to_id: $domainModel->getToId(),
            relation: $domainModel->getRelation(),
            weight: $domainModel->getWeight(),
            source: $domainModel->getSource(),
            created_at: $domainModel->getCreatedAt() ?? $now,
            updated_at: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}
