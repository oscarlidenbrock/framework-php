<?php

declare(strict_types=1);

namespace Module\Content\Administration;

use Framework\Database\DatabaseRegistry;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeRegistry;
use Module\Content\Access\ContentPolicy;
use Module\Content\ContentType\ContentType;
use Module\Content\ContentType\ContentTypeManager;

final class ContentStructure
{
    public function __construct(
        private readonly ContentTypeManager $types,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly DatabaseRegistry $databases,
        private readonly ContentPolicy $policy,
    ) {
    }

    /** @return list<string> */
    public function connections(): array
    {
        $this->policy->require('content.structure_view');
        return array_keys($this->databases->all());
    }

    public function connection(?string $name): string
    {
        $this->policy->require('content.structure_view');
        return $this->types->database($name)->name;
    }

    /** @return list<ContentType> */
    public function all(string $connection): array
    {
        $this->policy->require('content.structure_view');
        return $this->types->all($connection);
    }

    public function type(string $namespace, string $connection): ContentType
    {
        $this->policy->require('content.structure_view');
        return $this->types->requireType($namespace, $connection);
    }

    /** @return array<string, FieldDefinition> */
    public function fields(string $namespace, string $connection): array
    {
        $this->policy->require('content.structure_view');
        return $this->types->fields($namespace, $connection);
    }

    /** @return list<string> */
    public function fieldTypes(): array
    {
        $this->policy->require('content.structure_view');
        return $this->fieldTypes->ids();
    }

    public function create(string $namespace, string $label, string $description, string $connection): ContentType
    {
        $this->policy->require('content.structure_manage');
        return $this->types->create($namespace, $label, $description, connection: $connection, applySchema: false);
    }

    /** @param array<string, mixed> $definition */
    public function addField(string $namespace, array $definition, string $connection): FieldDefinition
    {
        $this->policy->require('content.structure_manage');
        return $this->types->addField($namespace, $definition, $connection);
    }

    /** @param array<string, mixed> $changes */
    public function updateField(string $namespace, string $field, array $changes, string $connection): FieldDefinition
    {
        $this->policy->require('content.structure_manage');
        return $this->types->updateField($namespace, $field, $changes, $connection);
    }

    /** @return list<array{kind: string, name: string, destructive: bool}> */
    public function diff(string $namespace, string $connection): array
    {
        $this->policy->require('content.structure_view');
        return $this->types->schema($namespace, $connection)->diff();
    }

    public function applySafe(string $namespace, string $connection): void
    {
        $this->policy->require('content.schema_apply');
        $schema = $this->types->schema($namespace, $connection);
        foreach ($schema->diff() as $change) {
            if ($change['destructive']) {
                throw new \LogicException('This schema change requires a data migration or CLI review. It cannot be applied from administration.');
            }
        }
        $schema->apply();
    }
}
