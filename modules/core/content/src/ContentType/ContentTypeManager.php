<?php

declare(strict_types=1);

namespace Module\Content\ContentType;

use Framework\Config\ConfigManager;
use Framework\Database\DatabaseManager;
use Framework\Database\DatabaseRegistry;
use Framework\Entity\EntityTypeRegistry;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeRegistry;

final class ContentTypeManager
{
    public function __construct(
        private readonly DatabaseRegistry $databases,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly EntityTypeRegistry $entityTypes,
        private readonly ConfigManager $config,
    ) {
    }

    public function create(string $namespace, string $label, string $description = '', ?string $module = null, ?string $connection = null): ContentType
    {
        $namespace = strtolower($namespace);
        self::validateNamespace($namespace);
        if (trim($label) === '' || mb_strlen($label) > 255 || $module !== null && !preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
            throw new \InvalidArgumentException('Invalid content type label or module.');
        }
        $database = $this->database($connection);
        $this->requireRegistryTable($database);
        if ($this->get($namespace, $database->name) !== null) {
            throw new \InvalidArgumentException("Content type already exists: {$namespace}");
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $database->execute('INSERT INTO content_types (namespace, label, description, module, connection_name, schema_status, created_at, updated_at) VALUES (:namespace, :label, :description, :module, :connection, :status, :created, :updated)', [
            'namespace' => $namespace, 'label' => $label, 'description' => $description, 'module' => $module,
            'connection' => $database->name, 'status' => 'pending', 'created' => $now, 'updated' => $now,
        ]);
        $type = new ContentType($namespace, $label, $description, $module, $database->name, 'pending');
        try {
            $schema = $this->schema($namespace, $database->name);
            $schema->installDefinitions(self::systemFields());
            $schema->apply(true);
        } catch (\Throwable $exception) {
            $database->execute('UPDATE content_types SET schema_status = :status WHERE namespace = :namespace', ['status' => 'error', 'namespace' => $namespace]);
            throw $exception;
        }
        $created = $this->requireType($namespace, $database->name);
        return $created;
    }

    public function get(string $namespace, ?string $connection = null): ?ContentType
    {
        self::validateNamespace($namespace);
        $database = $this->database($connection);
        $this->requireRegistryTable($database);
        $row = $database->queryOne('SELECT namespace, label, description, module, connection_name, schema_status FROM content_types WHERE namespace = :namespace', ['namespace' => $namespace]);
        if ($row === null) {
            return null;
        }
        $type = new ContentType($row['namespace'], $row['label'], $row['description'] ?? '', $row['module'], $row['connection_name'], $row['schema_status']);
        $this->entityTypes->remember($type);
        return $type;
    }

    /** @return list<ContentType> */
    public function all(?string $connection = null): array
    {
        $database = $this->database($connection);
        $this->requireRegistryTable($database);
        return array_map(function (array $row): ContentType {
            $type = new ContentType($row['namespace'], $row['label'], $row['description'] ?? '', $row['module'], $row['connection_name'], $row['schema_status']);
            $this->entityTypes->remember($type);
            return $type;
        }, $database->query('SELECT namespace, label, description, module, connection_name, schema_status FROM content_types ORDER BY namespace'));
    }

    /** @return array<string, FieldDefinition> */
    public function fields(string $namespace, ?string $connection = null): array
    {
        $type = $this->requireType($namespace, $connection);
        $rows = $this->database($type->connection)->query('SELECT field_name, label, field_type, is_system, required, default_value, settings, weight FROM ' . self::fieldsTable($namespace) . ' ORDER BY weight, id');
        $fields = [];
        foreach ($rows as $row) {
            $field = FieldDefinition::fromRow($row);
            $fields[$field->name] = $field;
        }
        return $fields;
    }

    /** @param array<string, mixed> $definition */
    public function addField(string $namespace, array $definition, ?string $connection = null): FieldDefinition
    {
        $type = $this->requireType($namespace, $connection);
        $field = FieldDefinition::fromArray($definition);
        if (in_array($field->name, ['title', 'created_at', 'updated_at', 'published', 'owner'], true)) {
            throw new \InvalidArgumentException('System fields cannot be replaced.');
        }
        if (isset($this->fields($namespace, $type->connection)[$field->name])) {
            throw new \InvalidArgumentException("Field already exists: {$field->name}");
        }
        $this->validateField($field);
        $database = $this->database($type->connection);
        $database->transaction(function () use ($namespace, $type, $field, $database): void {
            $this->markPending($namespace, $type->connection);
            $this->writeField($database, $namespace, $field);
        });
        return $field;
    }

    /** @param array<string, mixed> $changes */
    public function updateField(string $namespace, string $fieldName, array $changes, ?string $connection = null): FieldDefinition
    {
        $type = $this->requireType($namespace, $connection);
        $old = $this->fields($namespace, $type->connection)[$fieldName] ?? throw new \InvalidArgumentException("Unknown field: {$fieldName}");
        if (array_diff(array_keys($changes), ['label', 'type', 'required', 'default', 'settings', 'weight']) !== []) {
            throw new \InvalidArgumentException('Field names cannot be changed.');
        }
        if ($old->system && $fieldName !== 'title' && array_diff(array_keys($changes), ['label', 'weight']) !== []) {
            throw new \InvalidArgumentException('System field constraints cannot be changed.');
        }
        if ($old->system && $fieldName === 'title' && array_diff(array_keys($changes), ['label', 'settings', 'weight']) !== []) {
            throw new \InvalidArgumentException('Title may change only its label or length.');
        }
        $settings = $changes['settings'] ?? $old->settings;
        if (!is_array($settings) || isset($changes['label']) && !is_string($changes['label']) || isset($changes['type']) && !is_string($changes['type']) || isset($changes['required']) && !is_bool($changes['required']) || isset($changes['weight']) && !is_int($changes['weight'])) {
            throw new \InvalidArgumentException('Invalid field update options.');
        }
        if ($old->system && $fieldName === 'title' && array_diff(array_keys($settings), ['length']) !== []) {
            throw new \InvalidArgumentException('Title supports only length setting.');
        }
        $updated = new FieldDefinition($old->name, $changes['label'] ?? $old->label, $changes['type'] ?? $old->type, $changes['required'] ?? $old->required, array_key_exists('default', $changes) ? $changes['default'] : $old->default, $settings, $changes['weight'] ?? $old->weight, $old->system);
        $this->validateField($updated);
        $database = $this->database($type->connection);
        $database->transaction(function () use ($namespace, $type, $updated, $fieldName, $database): void {
            $this->markPending($namespace, $type->connection);
            $database->execute('UPDATE ' . self::fieldsTable($namespace) . ' SET label = :label, field_type = :type, required = :required, length = :length, ' . $database->connection()->quoteIdentifier('precision') . ' = :precision, scale = :scale, default_value = :default, settings = :settings, weight = :weight, updated_at = :updated WHERE field_name = :field', [
                'label' => $updated->label, 'type' => $updated->type, 'required' => (int) $updated->required,
                'length' => $updated->settings['length'] ?? null, 'precision' => $updated->settings['precision'] ?? null,
                'scale' => $updated->settings['scale'] ?? null, 'default' => $updated->default === null ? null : json_encode($updated->default, JSON_THROW_ON_ERROR),
                'settings' => json_encode($updated->settings, JSON_THROW_ON_ERROR), 'weight' => $updated->weight, 'updated' => gmdate('Y-m-d H:i:s.u'), 'field' => $fieldName,
            ]);
        });
        return $updated;
    }

    public function removeField(string $namespace, string $fieldName, ?string $connection = null): void
    {
        $field = $this->fields($namespace, $connection)[$fieldName] ?? throw new \InvalidArgumentException("Unknown field: {$fieldName}");
        if ($field->system) {
            throw new \InvalidArgumentException('System fields cannot be removed.');
        }
        throw new \LogicException('Removing fields requires an explicit data migration.');
    }

    public function schema(string $namespace, ?string $connection = null): ContentSchemaManager
    {
        $type = $this->requireType($namespace, $connection);
        return new ContentSchemaManager($this->database($type->connection), $this->fieldTypes, $type, $this);
    }

    public function database(?string $connection = null): DatabaseManager
    {
        $selected = $connection ?? $this->config->get('content.connection') ?? $this->databases->defaultName();
        if (!is_string($selected)) {
            throw new \InvalidArgumentException('Content connection is not configured.');
        }
        return $this->databases->manager($selected);
    }

    public function requireType(string $namespace, ?string $connection = null): ContentType
    {
        return $this->get($namespace, $connection) ?? throw new \InvalidArgumentException("Unknown content type: {$namespace}");
    }

    public function validateField(FieldDefinition $field): void
    {
        $type = $this->fieldTypes->get($field->type);
        $type->validateSettings($field->settings);
        if ($field->default !== null) {
            $type->normalize($field->default, $field);
        }
    }

    public function writeField(DatabaseManager $database, string $namespace, FieldDefinition $field): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $database->execute('INSERT INTO ' . self::fieldsTable($namespace) . ' (field_name, label, field_type, is_system, required, length, ' . $database->connection()->quoteIdentifier('precision') . ', scale, default_value, settings, weight, created_at, updated_at) VALUES (:name, :label, :type, :system, :required, :length, :precision, :scale, :default, :settings, :weight, :created, :updated)', [
            'name' => $field->name, 'label' => $field->label, 'type' => $field->type, 'system' => (int) $field->system,
            'required' => (int) $field->required, 'length' => $field->settings['length'] ?? null, 'precision' => $field->settings['precision'] ?? null,
            'scale' => $field->settings['scale'] ?? null, 'default' => $field->default === null ? null : json_encode($field->default, JSON_THROW_ON_ERROR),
            'settings' => json_encode($field->settings, JSON_THROW_ON_ERROR), 'weight' => $field->weight, 'created' => $now, 'updated' => $now,
        ]);
    }

    public function markPending(string $namespace, string $connection): void
    {
        $database = $this->database($connection);
        $changed = $database->execute("UPDATE content_types SET schema_status = 'pending', updated_at = :updated WHERE namespace = :namespace AND schema_status <> 'applying'", ['updated' => gmdate('Y-m-d H:i:s.u'), 'namespace' => $namespace]);
        if ($changed !== 1) {
            throw new \LogicException('Content schema is being applied.');
        }
    }

    public static function fieldsTable(string $namespace): string
    {
        self::validateNamespace($namespace);
        return 'content_' . $namespace . '_fields';
    }

    public static function dataTable(string $namespace): string
    {
        self::validateNamespace($namespace);
        return 'content_' . $namespace . '_data';
    }

    public static function validateNamespace(string $namespace): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $namespace) || in_array($namespace, ['type', 'types', 'item', 'field', 'fields', 'data', 'system'], true)) {
            throw new \InvalidArgumentException('Invalid or reserved content namespace.');
        }
    }

    /** @return list<FieldDefinition> */
    public static function systemFields(): array
    {
        return [
            new FieldDefinition('title', 'Title', 'string', true, null, ['length' => 255], 0, true),
            new FieldDefinition('created_at', 'Created at', 'datetime', true, null, [], 1, true),
            new FieldDefinition('updated_at', 'Updated at', 'datetime', true, null, [], 2, true),
            new FieldDefinition('published', 'Published', 'boolean', true, false, [], 3, true),
            new FieldDefinition('owner', 'Owner', 'bigint', true, 0, [], 4, true),
        ];
    }

    private function requireRegistryTable(DatabaseManager $database): void
    {
        if (!$database->connection()->createSchemaManager()->tablesExist(['content_types'])) {
            throw new \LogicException("Content migration is pending on connection {$database->name}; run database:migrate first.");
        }
    }
}
