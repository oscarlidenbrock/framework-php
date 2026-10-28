<?php

declare(strict_types=1);

namespace Framework\Database;

use Framework\Module\Module;
use Symfony\Component\Yaml\Yaml;

final class DatabaseRegistry
{
    /** @var array<string, DatabaseManager> */
    private array $managers = [];
    private ?string $defaultName = null;

    /** @param list<Module> $enabledModules */
    public function __construct(string $rootPath, string $configPath, array $enabledModules)
    {
        if (!is_file($configPath)) {
            $this->assertNoModuleDatabaseConfiguration($enabledModules);
            return;
        }
        $document = Yaml::parseFile($configPath);
        if (!is_array($document) || array_keys($document) !== ['database'] || !is_array($document['database']) || array_diff(array_keys($document['database']), ['default', 'connections']) !== []) {
            throw new \InvalidArgumentException('Invalid database configuration structure.');
        }
        $database = $document['database'];
        $connections = $database['connections'] ?? null;
        $default = $database['default'] ?? null;
        if (!is_string($default) || !self::validName($default) || !is_array($connections) || $connections === [] || array_is_list($connections) || !isset($connections[$default])) {
            throw new \InvalidArgumentException('Database default must name a configured connection.');
        }

        $definitions = [];
        foreach ($connections as $name => $definition) {
            if (!is_string($name) || !self::validName($name) || !is_array($definition) || array_is_list($definition) && $definition !== [] || array_diff(array_keys($definition), ['url_env', 'params', 'orm', 'migrations_table']) !== []) {
                throw new \InvalidArgumentException("Invalid database connection definition: {$name}");
            }
            $urlEnv = $definition['url_env'] ?? null;
            $params = $definition['params'] ?? null;
            $orm = $definition['orm'] ?? false;
            $table = $definition['migrations_table'] ?? 'doctrine_migration_versions';
            if ($urlEnv !== null && (!is_string($urlEnv) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $urlEnv)) || $params !== null && (!is_array($params) || $params === [] || array_is_list($params)) || $urlEnv === null && $params === null || !is_bool($orm) || !is_string($table) || !preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                throw new \InvalidArgumentException("Invalid database connection options: {$name}");
            }
            if ($params !== null) {
                foreach ($params as $key => $value) {
                    if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                        throw new \InvalidArgumentException("Invalid DBAL parameter name for connection {$name}");
                    }
                }
                if ($urlEnv === null && !isset($params['driver']) && !isset($params['url']) && !isset($params['driverClass'])) {
                    throw new \InvalidArgumentException("DBAL parameters need a driver or URL for connection {$name}");
                }
            }
            $definitions[$name] = ['url_env' => $urlEnv, 'params' => $params ?? [], 'orm' => $orm, 'migrations_table' => $table];
        }

        $mappings = array_fill_keys(array_keys($definitions), []);
        $migrations = array_fill_keys(array_keys($definitions), []);
        $mappingPaths = [];
        $migrationPaths = [];
        foreach ($enabledModules as $module) {
            $configuration = $module->database;
            $preferred = $configuration['entity_manager'] ?? $default;
            if (!is_string($preferred) || !self::validName($preferred) || !isset($definitions[$preferred]) || isset($configuration['entity_manager']) && !$definitions[$preferred]['orm']) {
                throw new \InvalidArgumentException("Unknown entity manager for module {$module->id}");
            }
            $explicitMappings = $configuration['mappings'] ?? [];
            $explicitMigrations = $configuration['migrations'] ?? [];
            if (!is_array($explicitMappings) || array_is_list($explicitMappings) && $explicitMappings !== [] || !is_array($explicitMigrations) || array_is_list($explicitMigrations) && $explicitMigrations !== []) {
                throw new \InvalidArgumentException("Invalid database mappings for module {$module->id}");
            }
            $autoEntity = $module->path . '/src/Entity';
            $hasExplicitEntity = false;
            foreach ($explicitMappings as $connection => $paths) {
                $this->requireConnection($definitions, $connection, $module->id);
                if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
                    throw new \InvalidArgumentException("Invalid mapping paths for module {$module->id}");
                }
                foreach ($paths as $path) {
                    $real = $this->moduleDirectory($module, $path);
                    if ($real === realpath($autoEntity)) {
                        $hasExplicitEntity = true;
                    }
                    $this->addMappingPath($mappings, $mappingPaths, $connection, $real);
                }
            }
            if (is_dir($autoEntity) && !$hasExplicitEntity) {
                $this->addMappingPath($mappings, $mappingPaths, $preferred, realpath($autoEntity));
            }

            $autoMigrations = $module->path . '/migrations';
            $hasExplicitMigrations = false;
            foreach ($explicitMigrations as $connection => $groups) {
                $this->requireConnection($definitions, $connection, $module->id);
                if (!is_array($groups) || $groups === [] || array_is_list($groups)) {
                    throw new \InvalidArgumentException("Invalid migration groups for module {$module->id}");
                }
                foreach ($groups as $namespace => $path) {
                    if (!is_string($namespace) || !str_starts_with($namespace, 'Module\\' . $module->namespace . '\\') || !preg_match('/^(?:[A-Z][A-Za-z0-9]*\\\\)+[A-Z][A-Za-z0-9]*$/', $namespace)) {
                        throw new \InvalidArgumentException("Invalid migration namespace for module {$module->id}");
                    }
                    $real = $this->moduleDirectory($module, $path);
                    if ($real === realpath($autoMigrations)) {
                        $hasExplicitMigrations = true;
                    }
                    if (isset($migrationPaths[$real]) || isset($migrations[$connection][$namespace])) {
                        throw new \InvalidArgumentException("Duplicate migration directory or namespace for module {$module->id}");
                    }
                    $migrationPaths[$real] = true;
                    $migrations[$connection][$namespace] = $real;
                }
            }
            if (is_dir($autoMigrations) && !$hasExplicitMigrations) {
                $real = realpath($autoMigrations);
                $namespace = 'Module\\' . $module->namespace . '\\Migrations';
                if (isset($migrationPaths[$real]) || isset($migrations[$preferred][$namespace])) {
                    throw new \InvalidArgumentException("Duplicate migration directory for module {$module->id}");
                }
                $migrationPaths[$real] = true;
                $migrations[$preferred][$namespace] = $real;
            }
        }

        foreach ($definitions as $name => $definition) {
            if (!$definition['orm'] && $mappings[$name] !== []) {
                throw new \InvalidArgumentException("Entity mappings require ORM on connection {$name}");
            }
            $this->managers[$name] = new DatabaseManager($name, $definition, $mappings[$name], $migrations[$name], $rootPath);
        }
        $this->defaultName = $default;
    }

    public function manager(?string $name = null): DatabaseManager
    {
        if ($this->managers === []) {
            throw new \LogicException('No database connections configured.');
        }
        $selected = $name ?? $this->defaultName;

        return $this->managers[$selected] ?? throw new \InvalidArgumentException("Database connection not found: {$selected}");
    }

    /** @return array<string, DatabaseManager> */
    public function all(): array
    {
        return $this->managers;
    }

    public function defaultName(): ?string
    {
        return $this->defaultName;
    }

    public function resetRequest(): void
    {
        foreach ($this->managers as $manager) {
            $manager->resetRequest();
        }
    }

    public function validateMappings(): void
    {
        $owners = [];
        $metadataByConnection = [];
        foreach ($this->managers as $name => $manager) {
            try {
                $em = $manager->entityManager();
            } catch (\LogicException) {
                continue;
            }
            $metadataByConnection[$name] = $em->getMetadataFactory()->getAllMetadata();
            foreach ($metadataByConnection[$name] as $metadata) {
                if (isset($owners[$metadata->name])) {
                    throw new \InvalidArgumentException("Entity {$metadata->name} mapped to multiple EntityManagers");
                }
                $owners[$metadata->name] = $name;
            }
        }
        foreach ($metadataByConnection as $name => $metadataList) {
            foreach ($metadataList as $metadata) {
                foreach ($metadata->getAssociationMappings() as $association) {
                    if (isset($owners[$association['targetEntity']]) && $owners[$association['targetEntity']] !== $name) {
                        throw new \InvalidArgumentException("Cross-connection ORM association from {$metadata->name} is forbidden");
                    }
                }
            }
        }
    }

    /** @param list<Module> $modules */
    private function assertNoModuleDatabaseConfiguration(array $modules): void
    {
        foreach ($modules as $module) {
            if ($module->database !== [] || is_dir($module->path . '/src/Entity') || is_dir($module->path . '/migrations')) {
                throw new \InvalidArgumentException("Module {$module->id} requires config/database.yaml");
            }
        }
    }

    private static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    /** @param array<string, array<string, mixed>> $definitions */
    private function requireConnection(array $definitions, mixed $name, string $moduleId): void
    {
        if (!is_string($name) || !self::validName($name) || !isset($definitions[$name])) {
            throw new \InvalidArgumentException("Unknown database connection for module {$moduleId}");
        }
    }

    private function moduleDirectory(Module $module, mixed $path): string
    {
        if (!is_string($path) || !preg_match('#^[A-Za-z0-9_./-]+$#', $path) || str_starts_with($path, '/') || str_contains($path, '//') || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            throw new \InvalidArgumentException("Invalid module database path: {$module->id}");
        }
        $real = realpath($module->path . '/' . $path);
        $root = realpath($module->path);
        if ($real === false || $root === false || !is_dir($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Missing or unsafe module database directory: {$module->id}");
        }

        return $real;
    }

    /** @param array<string, list<string>> $mappings
     *  @param array<string, string> $owners
     */
    private function addMappingPath(array &$mappings, array &$owners, string $connection, string $path): void
    {
        foreach ($owners as $existing => $owner) {
            if ($existing === $path || str_starts_with($existing, $path . DIRECTORY_SEPARATOR) || str_starts_with($path, $existing . DIRECTORY_SEPARATOR)) {
                throw new \InvalidArgumentException("Overlapping entity mappings between {$owner} and {$connection}");
            }
        }
        $owners[$path] = $connection;
        $mappings[$connection][] = $path;
    }
}
