<?php

declare(strict_types=1);

namespace Framework\Field;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Platforms\AbstractPlatform;

interface FieldTypeInterface
{
    public function id(): string;

    /** @param array<string, mixed> $settings */
    public function validateSettings(array $settings): void;

    public function normalize(mixed $value, FieldDefinition $field): mixed;

    public function toDatabase(mixed $value, FieldDefinition $field): mixed;

    public function fromDatabase(mixed $value, FieldDefinition $field): mixed;

    public function addColumn(Table $table, FieldDefinition $field, AbstractPlatform $platform): void;
}
