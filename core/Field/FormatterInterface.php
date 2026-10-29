<?php

declare(strict_types=1);

namespace Framework\Field;

interface FormatterInterface
{
    public function id(): string;

    public function template(): string;

    /** @return array<string, mixed> */
    public function variables(FieldDefinition $field, mixed $value): array;
}
