<?php

declare(strict_types=1);

namespace Framework\Field;

interface WidgetInterface
{
    public function id(): string;

    public function template(): string;

    /** @return array<string, mixed> */
    public function variables(FieldDefinition $field, mixed $value): array;

    public function submit(FieldDefinition $field, mixed $value, bool $present): mixed;
}
