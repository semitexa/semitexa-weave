<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

/**
 * A directed, typed edge between two nodes. `relation` is an open vocabulary
 * ({@see Relation}); `weight` is a 0–100 confidence
 * (100 = user-asserted, lower = inferred). `source` records provenance.
 * Immutable read model; mutations go through the store.
 */
final readonly class Edge
{
    public function __construct(
        public string $id,
        public string $fromId,
        public string $toId,
        public string $relation,
        public int $weight = 100,
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
            'from' => $this->fromId,
            'to' => $this->toId,
            'relation' => $this->relation,
            'weight' => $this->weight,
            'source' => $this->source,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
