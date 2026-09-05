<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

/**
 * A directed, typed edge between two nodes. `relation` is an open vocabulary
 * ({@see Relation}); `weight` is a 0–100 confidence
 * (100 = user-asserted, lower = inferred). `source` records provenance.
 * Immutable; mutations go through the store.
 */
final readonly class Edge
{
    public function __construct(
        private string $id,
        private string $fromId,
        private string $toId,
        private string $relation,
        private int $weight = 100,
        private string $source = '',
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
        private ?string $tenantId = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFromId(): string
    {
        return $this->fromId;
    }

    public function getToId(): string
    {
        return $this->toId;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getWeight(): int
    {
        return $this->weight;
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

    public function getTenantId(): ?string
    {
        return $this->tenantId;
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
            'created_at' => $this->createdAt?->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
        ];
    }
}
