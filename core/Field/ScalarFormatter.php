<?php

declare(strict_types=1);

namespace Framework\Field;

final class ScalarFormatter implements FormatterInterface
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
        return '@content/display/field.html.twig';
    }

    public function variables(FieldDefinition $field, mixed $value): array
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        return ['value' => $value];
    }
}
