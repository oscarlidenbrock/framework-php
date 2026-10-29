<?php

declare(strict_types=1);

namespace Module\Content\Form;

use Framework\Field\FieldTypeRegistry;
use Framework\Field\WidgetRegistry;
use Framework\Template\TemplateManager;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentRecord;
use Module\Content\Storage\ContentStorage;

final class ContentFormManager
{
    public function __construct(
        private readonly ContentTypeManager $types,
        private readonly ContentStorage $storage,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly WidgetRegistry $widgets,
        private readonly TemplateManager $templates,
    ) {
    }

    public function build(string $type, ?ContentRecord $record = null, ?string $connection = null): ContentForm
    {
        $definition = $this->types->requireType($type, $connection ?? $record?->connection);
        if ($record !== null && ($record->type !== $type || $record->connection !== $definition->connection)) {
            throw new \InvalidArgumentException('Form record does not match its content type or connection.');
        }
        return new ContentForm($type, $definition->connection, $record, $this->types, $this->storage, $this->fieldTypes, $this->widgets, $this->templates);
    }
}
