<?php

declare(strict_types=1);

namespace Framework\Field;

final class FieldDefinition
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly array $settings = [],
        public readonly int $weight = 0,
        public readonly bool $system = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) || $name === 'id' || !preg_match('/^[a-z][a-z0-9_]*$/', $type) || trim($label) === '') {
            throw new \InvalidArgumentException('Invalid field name, type or label.');
        }
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        if (array_diff(array_keys($definition), ['name', 'label', 'type', 'required', 'default', 'settings', 'weight']) !== []) {
            throw new \InvalidArgumentException('Unknown field definition option.');
        }
        $name = $definition['name'] ?? null;
        $type = $definition['type'] ?? null;
        $label = $definition['label'] ?? $name;
        $required = $definition['required'] ?? false;
        $settings = $definition['settings'] ?? [];
        $weight = $definition['weight'] ?? 0;
        if (!is_string($name) || !is_string($type) || !is_string($label) || !is_bool($required) || !is_array($settings) || !is_int($weight)) {
            throw new \InvalidArgumentException('Invalid field definition.');
        }

        return new self($name, $label, $type, $required, $definition['default'] ?? null, $settings, $weight);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['field_name'],
            $row['label'],
            $row['field_type'],
            (bool) $row['required'],
            $row['default_value'] === null ? null : json_decode($row['default_value'], true, flags: JSON_THROW_ON_ERROR),
            json_decode($row['settings'], true, flags: JSON_THROW_ON_ERROR),
            (int) $row['weight'],
            (bool) $row['is_system'],
        );
    }
}
