<?php

declare(strict_types=1);

namespace Module\Content\Storage;

final class ContentRecord
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public readonly string $type,
        public readonly string $connection,
        private array $values,
        private ?int $id = null,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Content ID is already assigned.');
        }
        $this->id = $id;
    }

    public function get(string $field): mixed
    {
        if (!array_key_exists($field, $this->values)) {
            throw new \InvalidArgumentException("Unknown content field: {$field}");
        }
        return $this->values[$field];
    }

    public function set(string $field, mixed $value): void
    {
        if (!array_key_exists($field, $this->values)) {
            throw new \InvalidArgumentException("Unknown content field: {$field}");
        }
        if (in_array($field, ['created_at', 'updated_at', 'owner'], true)) {
            throw new \InvalidArgumentException("Field {$field} is managed by storage.");
        }
        $this->values[$field] = $value;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function replace(array $values): void
    {
        $this->values = $values;
    }
}
