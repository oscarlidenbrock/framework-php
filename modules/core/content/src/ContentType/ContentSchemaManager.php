<?php

declare(strict_types=1);

namespace Module\Content\ContentType;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Framework\Database\DatabaseManager;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeRegistry;

final class ContentSchemaManager
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ContentType $type,
        private readonly ContentTypeManager $types,
    ) {
    }

    /** @param list<FieldDefinition> $definitions */
    public function installDefinitions(array $definitions): void
    {
        $schema = $this->database->connection()->createSchemaManager();
        $name = ContentTypeManager::fieldsTable($this->type->namespace);
        if (!$schema->tablesExist([$name])) {
            $table = new Table($name);
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('field_name', 'string', ['length' => 40]);
            $table->addColumn('label', 'string', ['length' => 255]);
            $table->addColumn('field_type', 'string', ['length' => 40]);
            $table->addColumn('is_system', 'boolean');
            $table->addColumn('required', 'boolean');
            $table->addColumn('length', 'integer', ['notnull' => false]);
            $table->addColumn('precision', 'integer', ['notnull' => false]);
            $table->addColumn('scale', 'integer', ['notnull' => false]);
            $table->addColumn('default_value', 'text', ['notnull' => false]);
            $table->addColumn('settings', 'text');
            $table->addColumn('weight', 'integer');
            $table->addColumn('created_at', 'datetime');
            $table->addColumn('updated_at', 'datetime');
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['field_name'], 'uniq_' . $this->type->namespace . '_field');
            $schema->createTable($table);
        }
        foreach ($definitions as $field) {
            $existing = $this->database->queryOne('SELECT id FROM ' . $name . ' WHERE field_name = :name', ['name' => $field->name]);
            if ($existing === null) {
                $this->types->validateField($field);
                $this->types->writeField($this->database, $this->type->namespace, $field);
            }
        }
    }

    /** @return list<array{kind: string, name: string, destructive: bool}> */
    public function diff(): array
    {
        $schema = $this->database->connection()->createSchemaManager();
        $fieldsName = ContentTypeManager::fieldsTable($this->type->namespace);
        if (!$schema->tablesExist([$fieldsName])) {
            return [['kind' => 'create_fields_table', 'name' => $fieldsName, 'destructive' => false]];
        }
        $name = ContentTypeManager::dataTable($this->type->namespace);
        if (!$schema->tablesExist([$name])) {
            return [['kind' => 'create_table', 'name' => $name, 'destructive' => false]];
        }
        return $this->describe($schema->createComparator()->compareTables($schema->introspectTable($name), $this->desiredTable()));
    }

    public function apply(bool $confirmed = false): void
    {
        $namespace = $this->type->namespace;
        $changes = $this->diff();
        if (in_array('drop_column', array_column($changes, 'kind'), true)) {
            throw new \LogicException('Extra physical columns require a manual data migration.');
        }
        if (in_array(true, array_column($changes, 'destructive'), true)) {
            if (!$confirmed) {
                throw new \LogicException('Destructive schema changes require confirmation.');
            }
            $name = ContentTypeManager::dataTable($namespace);
            if ($this->database->connection()->createSchemaManager()->tablesExist([$name]) && (int) $this->database->fetchValue('SELECT COUNT(*) FROM ' . $name) !== 0) {
                throw new \LogicException('Destructive schema changes on populated tables require a data migration.');
            }
        }
        $changed = $this->database->execute("UPDATE content_types SET schema_status = 'applying' WHERE namespace = :namespace AND schema_status <> 'applying'", ['namespace' => $namespace]);
        if ($changed !== 1) {
            throw new \LogicException('Content schema is already being applied.');
        }
        try {
            $this->installDefinitions(ContentTypeManager::systemFields());
            $schema = $this->database->connection()->createSchemaManager();
            $name = ContentTypeManager::dataTable($namespace);
            if (!$schema->tablesExist([$name])) {
                $schema->createTable($this->desiredTable());
            } else {
                $current = $schema->introspectTable($name);
                $difference = $schema->createComparator()->compareTables($current, $this->desiredTable());
                $changes = $this->describe($difference);
                if ($changes !== []) {
                    $destructive = array_filter($changes, static fn (array $change): bool => $change['destructive']);
                    if ($difference->getDroppedColumns() !== []) {
                        throw new \LogicException('Extra physical columns require a manual data migration.');
                    }
                    if ($destructive !== []) {
                        if (!$confirmed) {
                            throw new \LogicException('Destructive schema changes require confirmation.');
                        }
                        if ((int) $this->database->fetchValue('SELECT COUNT(*) FROM ' . $name) !== 0) {
                            throw new \LogicException('Destructive schema changes on populated tables require a data migration.');
                        }
                    }
                    $schema->alterTable($difference);
                }
            }
            if ($this->diff() !== []) {
                throw new \LogicException('Content schema did not match its definitions after applying changes.');
            }
            $this->database->execute("UPDATE content_types SET schema_status = 'applied', updated_at = :updated WHERE namespace = :namespace", ['updated' => gmdate('Y-m-d H:i:s.u'), 'namespace' => $namespace]);
        } catch (\Throwable $exception) {
            $this->database->execute("UPDATE content_types SET schema_status = 'error' WHERE namespace = :namespace", ['namespace' => $namespace]);
            throw $exception;
        }
    }

    private function desiredTable(): Table
    {
        $table = new Table(ContentTypeManager::dataTable($this->type->namespace));
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        foreach ($this->types->fields($this->type->namespace, $this->type->connection) as $field) {
            $this->fieldTypes->get($field->type)->addColumn($table, $field, $this->database->connection()->getDatabasePlatform());
        }
        $table->addIndex(['published'], 'idx_' . $this->type->namespace . '_published');
        $table->addIndex(['owner'], 'idx_' . $this->type->namespace . '_owner');
        $table->addIndex(['created_at'], 'idx_' . $this->type->namespace . '_created');
        return $table;
    }

    /** @return list<array{kind: string, name: string, destructive: bool}> */
    private function describe(TableDiff $difference): array
    {
        $changes = [];
        foreach ($difference->getAddedColumns() as $column) {
            $changes[] = ['kind' => 'add_column', 'name' => $column->getName(), 'destructive' => $column->getNotnull() && $column->getDefault() === null];
        }
        foreach ($difference->getChangedColumns() as $name => $change) {
            $old = $change->getOldColumn();
            $new = $change->getNewColumn();
            $destructive = $change->hasTypeChanged() || $change->hasLengthChanged() && $new->getLength() < $old->getLength() || $change->hasPrecisionChanged() && $new->getPrecision() < $old->getPrecision() || $change->hasScaleChanged() && $new->getScale() < $old->getScale() || $change->hasNotNullChanged() && $new->getNotnull();
            $changes[] = ['kind' => 'change_column', 'name' => $name, 'destructive' => $destructive];
        }
        foreach ($difference->getDroppedColumns() as $column) {
            $changes[] = ['kind' => 'drop_column', 'name' => $column->getName(), 'destructive' => true];
        }
        foreach ($difference->getAddedIndexes() as $index) {
            $changes[] = ['kind' => 'add_index', 'name' => $index->getName(), 'destructive' => false];
        }
        foreach ($difference->getDroppedIndexes() as $index) {
            $changes[] = ['kind' => 'drop_index', 'name' => $index->getName(), 'destructive' => false];
        }
        return $changes;
    }
}
