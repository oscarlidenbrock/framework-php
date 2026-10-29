<?php

declare(strict_types=1);

namespace Module\Content\Form;

use Framework\Field\FieldTypeRegistry;
use Framework\Field\WidgetRegistry;
use Framework\Template\TemplateManager;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentRecord;
use Module\Content\Storage\ContentStorage;

final class ContentForm
{
    public function __construct(
        private readonly string $type,
        private readonly string $connection,
        private readonly ?ContentRecord $existing,
        private readonly ContentTypeManager $types,
        private readonly ContentStorage $storage,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly WidgetRegistry $widgets,
        private readonly TemplateManager $templates,
    ) {
    }

    /** @param array<string, mixed> $values
     *  @param array<string, string> $errors
     */
    public function render(array $values = [], array $errors = []): string
    {
        $fields = [];
        foreach ($this->editableFields() as $field) {
            $widget = $this->widgets->get($field->type);
            $value = array_key_exists($field->name, $values) ? $values[$field->name] : ($this->existing?->get($field->name) ?? $field->default);
            $fields[] = array_merge($widget->variables($field, $value), [
                'name' => $field->name,
                'label' => $field->label,
                'required' => $field->required,
                'template' => $widget->template(),
                'error' => $errors[$field->name] ?? null,
            ]);
        }
        return $this->templates->render('@content/form/fields.html.twig', ['fields' => $fields]);
    }

    /** @param array<string, mixed> $submitted */
    public function submit(array $submitted): ContentFormResult
    {
        $fields = $this->editableFields();
        $errors = [];
        foreach ($submitted as $name => $_) {
            if (!isset($fields[$name])) {
                $errors[(string) $name] = 'Unknown or managed field.';
            }
        }
        $values = [];
        foreach ($fields as $field) {
            $present = array_key_exists($field->name, $submitted);
            if (!$present && $this->existing !== null && $field->type !== 'boolean') {
                $value = $this->existing->get($field->name);
            } else {
                try {
                    $value = $this->widgets->get($field->type)->submit($field, $submitted[$field->name] ?? null, $present);
                } catch (\InvalidArgumentException $exception) {
                    $errors[$field->name] = $exception->getMessage();
                    continue;
                }
            }
            try {
                $values[$field->name] = $this->fieldTypes->get($field->type)->normalize($value, $field);
            } catch (\InvalidArgumentException $exception) {
                $errors[$field->name] = $exception->getMessage();
            }
        }
        if ($errors !== []) {
            return new ContentFormResult(null, $errors);
        }
        if ($this->existing === null) {
            return new ContentFormResult($this->storage->create($this->type, $values, $this->connection), []);
        }
        $record = new ContentRecord($this->type, $this->connection, $this->existing->values(), $this->existing->id());
        foreach ($values as $name => $value) {
            $record->set($name, $value);
        }
        return new ContentFormResult($record, []);
    }

    /** @return array<string, \Framework\Field\FieldDefinition> */
    private function editableFields(): array
    {
        return array_diff_key($this->types->fields($this->type, $this->connection), array_flip(['created_at', 'updated_at', 'owner']));
    }
}
