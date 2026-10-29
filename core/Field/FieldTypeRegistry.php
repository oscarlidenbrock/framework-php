<?php

declare(strict_types=1);

namespace Framework\Field;

final class FieldTypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    public function __construct()
    {
        foreach (['string', 'text', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'datetime'] as $id) {
            $this->register(new ScalarFieldType($id));
        }
    }

    public function register(FieldTypeInterface $type): void
    {
        $id = $type->id();
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id) || isset($this->types[$id])) {
            throw new \InvalidArgumentException("Duplicate or invalid field type: {$id}");
        }
        $this->types[$id] = $type;
    }

    public function get(string $id): FieldTypeInterface
    {
        return $this->types[$id] ?? throw new \InvalidArgumentException("Unknown field type: {$id}");
    }
}
