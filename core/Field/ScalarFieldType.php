<?php

declare(strict_types=1);

namespace Framework\Field;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class ScalarFieldType implements FieldTypeInterface
{
    public function __construct(private readonly string $type)
    {
    }

    public function id(): string
    {
        return $this->type;
    }

    public function validateSettings(array $settings): void
    {
        $allowed = match ($this->type) {
            'string' => ['length'],
            'decimal' => ['precision', 'scale'],
            default => [],
        };
        if (array_diff(array_keys($settings), $allowed) !== []) {
            throw new \InvalidArgumentException("Invalid settings for {$this->type} field.");
        }
        if ($this->type === 'string') {
            $length = $settings['length'] ?? 255;
            if (!is_int($length) || $length < 1 || $length > 65535) {
                throw new \InvalidArgumentException('String length must be between 1 and 65535.');
            }
        }
        if ($this->type === 'decimal') {
            $precision = $settings['precision'] ?? 10;
            $scale = $settings['scale'] ?? 0;
            if (!is_int($precision) || $precision < 1 || $precision > 65 || !is_int($scale) || $scale < 0 || $scale > $precision) {
                throw new \InvalidArgumentException('Invalid decimal precision or scale.');
            }
        }
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            if ($field->required) {
                throw new \InvalidArgumentException("Field {$field->name} is required.");
            }
            return null;
        }
        $this->validateSettings($field->settings);

        return match ($this->type) {
            'string', 'text' => $this->normalizeString($value, $field),
            'integer', 'bigint' => $this->normalizeInteger($value, $field),
            'decimal' => $this->normalizeDecimal($value, $field),
            'float' => $this->normalizeFloat($value, $field),
            'boolean' => $this->normalizeBoolean($value, $field),
            'date', 'datetime' => $this->normalizeDate($value, $field),
            default => throw new \LogicException('Unsupported scalar field type.'),
        };
    }

    public function toDatabase(mixed $value, FieldDefinition $field): mixed
    {
        $normalized = $this->normalize($value, $field);
        if ($normalized instanceof \DateTimeImmutable) {
            return $normalized->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s.u');
        }
        if ($this->type === 'boolean') {
            return $normalized ? 1 : 0;
        }
        return $normalized;
    }

    public function fromDatabase(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($this->type) {
            'integer', 'bigint' => (int) $value,
            'float' => (float) $value,
            'boolean' => $this->databaseBoolean($value),
            'date', 'datetime' => new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')),
            default => $value,
        };
    }

    public function addColumn(Table $table, FieldDefinition $field, AbstractPlatform $platform): void
    {
        $this->validateSettings($field->settings);
        $type = $this->type === 'decimal' && $platform instanceof SQLitePlatform ? 'string' : $this->type;
        $options = ['notnull' => $field->required];
        if ($this->type === 'string') {
            $options['length'] = $field->settings['length'] ?? 255;
        }
        if ($this->type === 'decimal') {
            $options['precision'] = $field->settings['precision'] ?? 10;
            $options['scale'] = $field->settings['scale'] ?? 0;
            if ($platform instanceof SQLitePlatform) {
                $options['length'] = $options['precision'] + 3;
                unset($options['precision'], $options['scale']);
            }
        }
        if ($field->default !== null) {
            $options['default'] = $this->toDatabase($field->default, $field);
        }
        $table->addColumn($field->name, $type, $options);
    }

    private function normalizeString(mixed $value, FieldDefinition $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Field {$field->name} must be a string.");
        }
        if ($field->name === 'title' && trim($value) === '') {
            throw new \InvalidArgumentException('Title cannot be empty.');
        }
        if ($this->type === 'string' && mb_strlen($value) > ($field->settings['length'] ?? 255)) {
            throw new \InvalidArgumentException("Field {$field->name} exceeds its maximum length.");
        }
        return $value;
    }

    private function normalizeInteger(mixed $value, FieldDefinition $field): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("Field {$field->name} must be an integer.");
        }
        $integer = (int) $value;
        if ($this->type === 'integer' && ($integer < -2147483648 || $integer > 2147483647) || $field->name === 'owner' && $integer < 0) {
            throw new \InvalidArgumentException("Field {$field->name} is outside its allowed range.");
        }
        return $integer;
    }

    private function normalizeDecimal(mixed $value, FieldDefinition $field): string
    {
        if (!is_string($value) && !is_int($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', (string) $value)) {
            throw new \InvalidArgumentException("Field {$field->name} must be a decimal string or integer.");
        }
        $text = (string) $value;
        $digits = ltrim(explode('.', ltrim($text, '-'))[0], '0');
        $fraction = explode('.', $text)[1] ?? '';
        $precision = $field->settings['precision'] ?? 10;
        $scale = $field->settings['scale'] ?? 0;
        if (strlen($digits) > $precision - $scale || strlen($fraction) > $scale) {
            throw new \InvalidArgumentException("Field {$field->name} exceeds decimal precision or scale.");
        }
        return $text;
    }

    private function normalizeFloat(mixed $value, FieldDefinition $field): float
    {
        if (!is_int($value) && !is_float($value) || !is_finite((float) $value)) {
            throw new \InvalidArgumentException("Field {$field->name} must be a finite float.");
        }
        return (float) $value;
    }

    private function normalizeBoolean(mixed $value, FieldDefinition $field): bool
    {
        if (!is_bool($value) && $value !== 0 && $value !== 1) {
            throw new \InvalidArgumentException("Field {$field->name} must be boolean.");
        }
        return (bool) $value;
    }

    private function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                '', '0', 'false', 'f' => false,
                '1', 'true', 't' => true,
                default => throw new \UnexpectedValueException('Invalid boolean value returned by database.'),
            };
        }
        throw new \UnexpectedValueException('Invalid boolean value returned by database.');
    }

    private function normalizeDate(mixed $value, FieldDefinition $field): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Field {$field->name} must be a date.");
        }
        $format = $this->type === 'date' ? '!Y-m-d' : '!Y-m-d H:i:s.u';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s.u') !== $value) {
            throw new \InvalidArgumentException("Field {$field->name} has an invalid date.");
        }
        return $date;
    }
}
