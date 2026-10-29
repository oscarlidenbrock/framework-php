<?php

declare(strict_types=1);

namespace Module\Content\Display;

use Framework\Config\ConfigManager;
use Framework\Field\FormatterRegistry;
use Framework\Template\TemplateManager;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentRecord;

final class ContentViewManager
{
    public function __construct(
        private readonly ContentTypeManager $types,
        private readonly FormatterRegistry $formatters,
        private readonly TemplateManager $templates,
        private readonly ConfigManager $config,
    ) {
    }

    public function render(ContentRecord $record, string $mode = 'full'): string
    {
        $fields = $this->types->fields($record->type, $record->connection);
        $selected = $this->config->get('content.display_modes.' . $mode);
        if ($mode === 'full' && $selected === null) {
            $selected = array_keys($fields);
        }
        if (!is_array($selected) || !array_is_list($selected) || array_filter($selected, static fn (mixed $name): bool => !is_string($name) || !isset($fields[$name])) !== []) {
            throw new \InvalidArgumentException("Unknown or invalid content display mode: {$mode}");
        }
        $items = [];
        foreach ($selected as $name) {
            $field = $fields[$name];
            $formatter = $this->formatters->get($field->type);
            $items[] = array_merge($formatter->variables($field, $record->get($name)), ['name' => $name, 'label' => $field->label, 'template' => $formatter->template()]);
        }
        return $this->templates->render('@content/display/content.html.twig', ['fields' => $items, 'mode' => $mode]);
    }
}
