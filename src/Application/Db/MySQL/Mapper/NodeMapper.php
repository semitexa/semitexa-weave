<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Weave\Application\Db\MySQL\Model\NodeResource;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * The bridge between the `weave_node` row and one node of the graph.
 *
 * Three things the row carries that the node does not carry the same way: the
 * kind is a string column and a {@see NodeKind} in the graph, the properties
 * are a JSON column and a map in the graph, and `title_key` exists only in the
 * row — it is derived from the title here, so a rename can never leave the
 * dedup key pointing at the old one.
 */
#[AsMapper(resourceModel: NodeResource::class, domainModel: Node::class)]
final class NodeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof NodeResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        $decoded = json_decode($resourceModel->properties_json, true);
        $properties = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $properties[$key] = $value;
                }
            }
        }

        return new Node(
            id: $resourceModel->id,
            kind: NodeKind::from($resourceModel->kind),
            title: $resourceModel->title,
            properties: $properties,
            source: $resourceModel->source,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
            ref: $resourceModel->ext_ref,
            tenantId: $resourceModel->tenant_id,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof Node || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new NodeResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            kind: $domainModel->getKind()->value,
            title: $domainModel->getTitle(),
            title_key: $domainModel->titleKey(),
            ext_ref: $domainModel->getRef(),
            properties_json: (string) json_encode(
                $domainModel->getProperties(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            source: $domainModel->getSource(),
            created_at: $domainModel->getCreatedAt() ?? $now,
            updated_at: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}
