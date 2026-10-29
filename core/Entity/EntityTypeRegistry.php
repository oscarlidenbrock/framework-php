<?php

declare(strict_types=1);

namespace Framework\Entity;

final class EntityTypeRegistry
{
    /** @var array<string, EntityTypeInterface> */
    private array $types = [];

    public function register(EntityTypeInterface $type): void
    {
        $key = $type->connectionName() . '/' . $type->id();
        if (isset($this->types[$key])) {
            throw new \InvalidArgumentException("Duplicate entity type: {$key}");
        }
        $this->types[$key] = $type;
    }

    public function get(string $connection, string $id): EntityTypeInterface
    {
        return $this->types[$connection . '/' . $id] ?? throw new \InvalidArgumentException("Unknown entity type: {$connection}/{$id}");
    }

    public function remember(EntityTypeInterface $type): void
    {
        $key = $type->connectionName() . '/' . $type->id();
        if (isset($this->types[$key]) && $this->types[$key]::class !== $type::class) {
            throw new \InvalidArgumentException("Conflicting entity type: {$key}");
        }
        $this->types[$key] = $type;
    }
}
