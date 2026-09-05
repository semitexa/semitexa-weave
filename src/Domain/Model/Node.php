<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * A node in the Weave — one entity the OS knows about (a project, person, note,
 * file, …). `properties` is the open, per-kind schema seam (status, dates,
 * url, …) that queries and views hang on. `source` records provenance — which
 * turn/skill/import created it — so an inferred node can be told from an
 * asserted one. Immutable; mutations go through the store.
 *
 * The dedup key the row is unique on is NOT a field here: it is derived from
 * the title ({@see TitleKey::exact()}), so the mapper computes it on the way
 * down and nothing can hold a key that disagrees with the title beside it.
 */
final readonly class Node
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        private string $id,
        private NodeKind $kind,
        private string $title,
        private array $properties = [],
        private string $source = '',
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
        /** Set when the node mirrors a record outside the graph; then IT is the identity, not the title. */
        private ?string $ref = null,
        private ?string $tenantId = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKind(): NodeKind
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<string, mixed> */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getRef(): ?string
    {
        return $this->ref;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    /** The dedup key this node's title normalises to. */
    public function titleKey(): string
    {
        return TitleKey::exact($this->title);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'properties' => $this->properties,
            'source' => $this->source,
            'ref' => $this->ref,
            'created_at' => $this->createdAt?->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
        ];
    }
}
