<?php

declare(strict_types=1);

namespace Framework\Module;

use Composer\Autoload\ClassLoader;
use Framework\Runtime\RuntimePaths;
use Symfony\Component\Yaml\Yaml;

final class ModuleManager
{
    /** @var array<string, Module>|null */
    private ?array $discovered = null;

    public function __construct(
        private readonly string $rootPath,
        private readonly ClassLoader $loader,
        private readonly bool $useCache = false,
        private readonly string $workspace = 'default',
    ) {
    }

    /** @return array<string, Module> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        if ($this->useCache && ($cached = $this->readCache()) !== null) {
            return $this->discovered = $cached;
        }

        $modules = [];
        $namespaces = [];
        foreach ($this->roots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $entries = scandir($root);
            if ($entries === false) {
                throw new \RuntimeException("Cannot read module directory: {$root}");
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
                    continue;
                }
                $path = $root . '/' . $entry;
                $manifest = $path . '/module.yaml';
                if (!is_file($manifest)) {
                    throw new \InvalidArgumentException("Missing module manifest: {$manifest}");
                }
                $definition = Yaml::parseFile($manifest);
                if (!is_array($definition)) {
                    throw new \InvalidArgumentException("Invalid module manifest: {$manifest}");
                }
                $id = $definition['id'] ?? null;
                $name = $definition['name'] ?? null;
                $namespace = $definition['namespace'] ?? null;
                $version = $definition['version'] ?? null;
                $requires = $definition['requires'] ?? [];
                $database = $definition['database'] ?? [];
                if (array_key_exists('assets', $definition)) {
                    throw new \InvalidArgumentException("Direct module assets are forbidden; use config/libraries.yaml: {$manifest}");
                }
                if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id) || $id === 'theme' || !is_string($name) || trim($name) === '' || !is_string($namespace) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $namespace) || !is_string($version) || trim($version) === '' || !is_array($requires) || !array_is_list($requires) || !is_array($database) || array_is_list($database) && $database !== [] || array_diff(array_keys($definition), ['id', 'name', 'description', 'version', 'namespace', 'requires', 'database']) !== []) {
                    throw new \InvalidArgumentException("Invalid module manifest metadata: {$manifest}");
                }
                if (array_diff(array_keys($database), ['entity_manager', 'mappings', 'migrations']) !== []) {
                    throw new \InvalidArgumentException("Invalid module database configuration: {$manifest}");
                }
                foreach ($requires as $dependency) {
                    if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9_]*$/', $dependency)) {
                        throw new \InvalidArgumentException("Invalid dependency in {$manifest}");
                    }
                }
                if (count($requires) !== count(array_unique($requires))) {
                    throw new \InvalidArgumentException("Duplicate dependency in {$manifest}");
                }
                if (isset($modules[$id])) {
                    throw new \InvalidArgumentException("Duplicate module ID: {$id}");
                }
                $namespaceKey = strtolower($namespace);
                if (isset($namespaces[$namespaceKey])) {
                    throw new \InvalidArgumentException("Duplicate module namespace: {$namespace}");
                }

                $modules[$id] = new Module($id, $namespace, $path, $requires, $database);
                $namespaces[$namespaceKey] = true;
            }
        }

        if ($this->useCache) {
            $this->writeCache($modules);
        }

        return $this->discovered = $modules;
    }

    public function registerNamespaces(): void
    {
        foreach ($this->discover() as $module) {
            $this->loader->addPsr4('Module\\' . $module->namespace . '\\', $module->path . '/src');
        }
    }

    /** @param list<string> $ids
     *  @return list<Module>
     */
    public function enabled(array $ids): array
    {
        $modules = $this->discover();
        $requested = array_fill_keys($ids, true);
        $state = [];
        $ordered = [];
        $visit = function (string $id) use (&$visit, &$state, &$ordered, $modules, $requested): void {
            if (!isset($modules[$id])) {
                throw new \InvalidArgumentException("Enabled module not found: {$id}");
            }
            if (($state[$id] ?? null) === 'visiting') {
                throw new \InvalidArgumentException("Circular module dependency involving {$id}");
            }
            if (($state[$id] ?? null) === 'done') {
                return;
            }
            $state[$id] = 'visiting';
            foreach ($modules[$id]->requires as $dependency) {
                if (!isset($requested[$dependency])) {
                    throw new \InvalidArgumentException("Module {$id} requires enabled module {$dependency}");
                }
                $visit($dependency);
            }
            $state[$id] = 'done';
            $ordered[] = $modules[$id];
        };

        foreach ($ids as $id) {
            $visit($id);
        }

        return $ordered;
    }

    /** @return list<string> */
    private function roots(): array
    {
        return [
            $this->rootPath . '/modules/core',
            $this->rootPath . '/modules/contrib',
            $this->rootPath . '/modules/custom',
        ];
    }

    /** @return array<string, Module>|null */
    private function readCache(): ?array
    {
        $path = (new RuntimePaths($this->rootPath, $this->workspace))->cache('modules.json');
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        $cache = $contents === false ? null : json_decode($contents, true);
        if (!is_array($cache) || ($cache['version'] ?? null) !== 3 || ($cache['roots'] ?? null) !== $this->rootStamps() || !is_array($cache['modules'] ?? null) || !array_is_list($cache['modules'])) {
            return null;
        }

        $modules = [];
        foreach ($cache['modules'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['namespace'], $item['path'], $item['requires'], $item['stamp']) || !is_string($item['id']) || !preg_match('/^[a-z][a-z0-9_]*$/', $item['id']) || $item['id'] === 'theme' || !is_string($item['namespace']) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $item['namespace']) || !is_string($item['path']) || !is_array($item['requires']) || !array_is_list($item['requires']) || !is_array($item['stamp']) || !in_array(dirname($item['path']), $this->roots(), true) || $item['stamp'] !== $this->moduleStamp($item['path']) || isset($modules[$item['id']])) {
                return null;
            }
            foreach ($item['requires'] as $dependency) {
                if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9_]*$/', $dependency)) {
                    return null;
                }
            }
            if (!is_array($item['database'] ?? null) || array_is_list($item['database']) && $item['database'] !== [] || array_diff(array_keys($item['database']), ['entity_manager', 'mappings', 'migrations']) !== []) {
                return null;
            }
            $modules[$item['id']] = new Module($item['id'], $item['namespace'], $item['path'], $item['requires'], $item['database']);
        }

        return $modules;
    }

    /** @param array<string, Module> $modules */
    private function writeCache(array $modules): void
    {
        $directory = (new RuntimePaths($this->rootPath, $this->workspace))->cache();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create module cache directory: {$directory}");
        }
        $items = [];
        foreach ($modules as $module) {
            $items[] = [
                'id' => $module->id,
                'namespace' => $module->namespace,
                'path' => $module->path,
                'requires' => $module->requires,
                'database' => $module->database,
                'stamp' => $this->moduleStamp($module->path),
            ];
        }
        $contents = json_encode(['version' => 3, 'roots' => $this->rootStamps(), 'modules' => $items], JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, 'modules-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false || !rename($temporary, $directory . '/modules.json')) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Cannot write module discovery cache.');
        }
    }

    /** @return array<string, array<int|null>|null> */
    private function rootStamps(): array
    {
        $stamps = [];
        foreach ($this->roots() as $root) {
            $stamps[$root] = $this->stamp($root);
        }

        return $stamps;
    }

    /** @return array{0: array<int|null>|null, 1: array<int|null>|null} */
    private function moduleStamp(string $path): array
    {
        return [$this->stamp($path), $this->stamp($path . '/module.yaml')];
    }

    /** @return array<int|null>|null */
    private function stamp(string $path): ?array
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return null;
        }

        return [filemtime($path), filectime($path), filesize($path)];
    }
}
