<?php

declare(strict_types=1);

namespace Framework\Field;

final class ScalarWidget implements WidgetInterface
{
    public function __construct(private readonly string $type)
    {
    }

    public function id(): string
    {
        return $this->type;
    }

    public function template(): string
    {
        return '@content/form/field.html.twig';
    }

    public function variables(FieldDefinition $field, mixed $value): array
    {
        if (!is_scalar($value) && !$value instanceof \DateTimeInterface && $value !== null) {
            $value = null;
        }
        $control = match ($this->type) {
            'text' => 'textarea',
            'integer', 'bigint', 'decimal', 'float' => 'number',
            'boolean' => 'checkbox',
            'date' => 'date',
            'datetime' => 'datetime-local',
            default => 'text',
        };
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s');
        }
        return [
            'control' => $control,
            'value' => $value,
            'step' => $this->type === 'integer' || $this->type === 'bigint' ? '1' : ($this->type === 'decimal' || $this->type === 'float' ? 'any' : null),
            'maxlength' => $this->type === 'string' ? ($field->settings['length'] ?? 255) : null,
        ];
    }

    public function submit(FieldDefinition $field, mixed $value, bool $present): mixed
    {
        if ($this->type === 'boolean' && !$present) {
            return false;
        }
        if (!$present) {
            return $field->default;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException("Field {$field->name} must be scalar.");
        }
        if ($value === '' && $this->type !== 'string' && $this->type !== 'text') {
            return null;
        }
        if ($this->type === 'boolean') {
            if ($value !== '1' && $value !== 1 && $value !== true) {
                throw new \InvalidArgumentException("Field {$field->name} must be a checkbox value.");
            }
            return true;
        }
        if ($this->type === 'float' && is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        if ($this->type === 'datetime' && is_string($value) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d)?$/', $value)) {
            return str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '') . '.000000';
        }
        return $value;
    }
}
