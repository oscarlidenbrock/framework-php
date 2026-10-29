<?php

declare(strict_types=1);

namespace Module\FormExtension;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeInterface;
use Framework\Field\ScalarFieldType;

final class SlugFieldType implements FieldTypeInterface
{
    private readonly ScalarFieldType $string;

    public function __construct()
    {
        $this->string = new ScalarFieldType('string');
    }

    public function id(): string
    {
        return 'slug';
    }

    public function validateSettings(array $settings): void
    {
        $this->string->validateSettings($settings);
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        $value = $this->string->normalize($value, $field);
        if ($value !== null && !preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new \InvalidArgumentException('Slug must contain lowercase letters, numbers or hyphens.');
        }
        return $value;
    }

    public function toDatabase(mixed $value, FieldDefinition $field): mixed
    {
        return $this->normalize($value, $field);
    }

    public function fromDatabase(mixed $value, FieldDefinition $field): mixed
    {
        return $value;
    }

    public function addColumn(Table $table, FieldDefinition $field, AbstractPlatform $platform): void
    {
        $this->string->addColumn($table, $field, $platform);
    }
}
