<?php

declare(strict_types=1);

namespace Module\Content\Storage;

use Framework\Entity\EntityStorageInterface;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeRegistry;
use Module\Content\ContentType\ContentTypeManager;

final class ContentStorage implements EntityStorageInterface
{
    public function __construct(
        private readonly ContentTypeManager $types,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function create(string $type, array $values, ?string $connection = null): ContentRecord
    {
        $definition = $this->types->requireType($type, $connection);
        $fields = $this->types->fields($type, $definition->connection);
        foreach ($values as $name => $value) {
            if (!isset($fields[$name]) || in_array($name, ['created_at', 'updated_at', 'owner'], true)) {
                throw new \InvalidArgumentException("Unknown or managed content field: {$name}");
            }
        }
        $filled = [];
        foreach ($fields as $field) {
            $filled[$field->name] = array_key_exists($field->name, $values) ? $values[$field->name] : $field->default;
        }
        $filled['owner'] = 0;
        $filled['created_at'] = null;
        $filled['updated_at'] = null;

        return new ContentRecord($type, $definition->connection, $filled);
    }

    public function save(object $entity): void
    {
        $record = $this->requireRecord($entity);
        $this->assertSchemaReady($record->type, $record->connection);
        $fields = $this->types->fields($record->type, $record->connection);
        $values = $record->values();
        if (array_diff(array_keys($values), array_keys($fields)) !== []) {
            throw new \InvalidArgumentException('Content contains unknown fields.');
        }
        $database = $this->types->database($record->connection);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing = $record->id() === null ? null : $this->find($record->type, $record->id(), $record->connection);
        if ($record->id() !== null && $existing === null) {
            throw new \InvalidArgumentException('Content record no longer exists.');
        }
        if ($existing === null) {
            $values['created_at'] = $now;
            $values['updated_at'] = $now;
            $values['owner'] = 0;
        } else {
            if ($values['owner'] !== $existing->get('owner') || $values['created_at'] != $existing->get('created_at')) {
                throw new \InvalidArgumentException('Owner and creation time cannot be changed.');
            }
            $values['created_at'] = $existing->get('created_at');
            $changed = false;
            foreach ($fields as $field) {
                if (in_array($field->name, ['created_at', 'updated_at'], true)) {
                    continue;
                }
                if ($this->fieldTypes->get($field->type)->toDatabase($values[$field->name], $field) !== $this->fieldTypes->get($field->type)->toDatabase($existing->get($field->name), $field)) {
                    $changed = true;
                }
            }
            if (!$changed) {
                $record->replace($existing->values());
                return;
            }
            $values['updated_at'] = $now;
        }
        $parameters = [];
        $assignments = [];
        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;
            $parameters[$field->name] = $this->fieldTypes->get($field->type)->toDatabase($value, $field);
            $assignments[] = $database->connection()->quoteIdentifier($field->name) . ' = :' . $field->name;
        }
        $table = $database->connection()->quoteIdentifier(ContentTypeManager::dataTable($record->type));
        if ($existing === null) {
            $database->execute('INSERT INTO ' . $table . ' (' . implode(', ', array_map($database->connection()->quoteIdentifier(...), array_keys($parameters))) . ') VALUES (:' . implode(', :', array_keys($parameters)) . ')', $parameters);
            $record->assignId((int) $database->connection()->lastInsertId());
        } else {
            $parameters['id'] = $record->id();
            $database->execute('UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id', $parameters);
        }
        $record->replace($this->find($record->type, $record->id(), $record->connection)->values());
    }

    public function find(string $type, int $id, ?string $connection = null): ?ContentRecord
    {
        $definition = $this->types->requireType($type, $connection);
        $this->assertSchemaReady($type, $definition->connection);
        $database = $this->types->database($definition->connection);
        $row = $database->queryOne('SELECT * FROM ' . $database->connection()->quoteIdentifier(ContentTypeManager::dataTable($type)) . ' WHERE id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($type, $definition->connection, $row);
    }

    /** @param array<string, mixed> $criteria
     *  @return list<ContentRecord>
     */
    public function findBy(string $type, array $criteria, ?string $connection = null): array
    {
        $definition = $this->types->requireType($type, $connection);
        $this->assertSchemaReady($type, $definition->connection);
        $fields = $this->types->fields($type, $definition->connection);
        $database = $this->types->database($definition->connection);
        $predicates = [];
        $params = [];
        foreach ($criteria as $name => $value) {
            if (!isset($fields[$name])) {
                throw new \InvalidArgumentException("Unknown filter field: {$name}");
            }
            $identifier = $database->connection()->quoteIdentifier($name);
            if ($value === null) {
                $predicates[] = $identifier . ' IS NULL';
            } else {
                $predicates[] = $identifier . ' = :' . $name;
                $params[$name] = $this->fieldTypes->get($fields[$name]->type)->toDatabase($value, $fields[$name]);
            }
        }
        $sql = 'SELECT * FROM ' . $database->connection()->quoteIdentifier(ContentTypeManager::dataTable($type));
        if ($predicates !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $predicates);
        }
        $sql .= ' ORDER BY id';
        return array_map(fn (array $row): ContentRecord => $this->hydrate($type, $definition->connection, $row), $database->query($sql, $params));
    }

    public function delete(object $entity): void
    {
        $record = $this->requireRecord($entity);
        $this->assertSchemaReady($record->type, $record->connection);
        if ($record->id() === null) {
            throw new \InvalidArgumentException('Cannot delete unsaved content.');
        }
        $database = $this->types->database($record->connection);
        $database->execute('DELETE FROM ' . $database->connection()->quoteIdentifier(ContentTypeManager::dataTable($record->type)) . ' WHERE id = :id', ['id' => $record->id()]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(string $type, string $connection, array $row): ContentRecord
    {
        $values = [];
        foreach ($this->types->fields($type, $connection) as $field) {
            $values[$field->name] = $this->fieldTypes->get($field->type)->fromDatabase($row[$field->name] ?? null, $field);
        }
        return new ContentRecord($type, $connection, $values, (int) $row['id']);
    }

    private function assertSchemaReady(string $type, string $connection): void
    {
        $definition = $this->types->requireType($type, $connection);
        if ($definition->schemaStatus !== 'applied' || $this->types->schema($type, $connection)->diff() !== []) {
            throw new \LogicException("Content schema is pending or drifted: {$type}");
        }
    }

    private function requireRecord(object $entity): ContentRecord
    {
        if (!$entity instanceof ContentRecord) {
            throw new \InvalidArgumentException('ContentStorage accepts ContentRecord only.');
        }
        return $entity;
    }
}
