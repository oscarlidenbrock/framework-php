<?php

declare(strict_types=1);

namespace Module\FormExtension;

use Framework\Field\FieldDefinition;
use Framework\Field\FormatterInterface;

final class SlugFormatter implements FormatterInterface
{
    public function id(): string
    {
        return 'slug';
    }

    public function template(): string
    {
        return '@form_extension/display/slug.html.twig';
    }

    public function variables(FieldDefinition $field, mixed $value): array
    {
        return ['value' => $value];
    }
}
