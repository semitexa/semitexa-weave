<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * A node in the Weave — one entity the OS knows about (a project, person, note,
 * file, …). `properties` is the open, per-kind schema seam (status, dates,
 * url, …) that queries and views hang on. `source` records provenance — which
 * turn/skill/import created it — so an inferred node can be told from an
 * asserted one. Immutable read model; mutations go through the store.
 */
final readonly class Node
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public string $id,
        public NodeKind $kind,
        public string $title,
        public array $properties = [],
        public string $source = '',
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
