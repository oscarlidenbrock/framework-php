<?php

declare(strict_types=1);

namespace Module\FormExtension;

use Framework\Field\FieldDefinition;
use Framework\Field\WidgetInterface;

final class SlugWidget implements WidgetInterface
{
    public function id(): string
    {
        return 'slug';
    }

    public function template(): string
    {
        return '@form_extension/form/slug.html.twig';
    }

    public function variables(FieldDefinition $field, mixed $value): array
    {
        return ['value' => $value];
    }

    public function submit(FieldDefinition $field, mixed $value, bool $present): mixed
    {
        return $present ? $value : $field->default;
    }
}
