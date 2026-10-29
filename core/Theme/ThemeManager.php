<?php

declare(strict_types=1);

namespace Framework\Theme;

use Composer\Autoload\ClassLoader;
use Symfony\Component\Yaml\Yaml;

final class ThemeManager
{
    /** @var array<string, Theme>|null */
    private ?array $discovered = null;
    private ?Theme $active = null;
    private ?Theme $admin = null;

    public function __construct(
        private readonly string $rootPath,
        private readonly ClassLoader $loader,
        private readonly bool $useCache = false,
    ) {
    }

    /** @return array<string, Theme> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        if ($this->useCache && ($cached = $this->readCache()) !== null) {
            return $this->discovered = $cached;
        }

        $themes = [];
        $namespaces = [];
        foreach ($this->roots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $entries = scandir($root);
            if ($entries === false) {
                throw new \RuntimeException("Cannot read theme directory: {$root}");
            }
            foreach ($entries as $entry) {
                $path = $root . '/' . $entry;
                if ($entry === '.' || $entry === '..' || !is_dir($path)) {
                    continue;
                }
                $manifest = $path . '/theme.yaml';
                if (!is_file($manifest)) {
                    throw new \InvalidArgumentException("Missing theme manifest: {$manifest}");
                }
                $data = Yaml::parseFile($manifest);
                if (!is_array($data)) {
                    throw new \InvalidArgumentException("Invalid theme manifest: {$manifest}");
                }
                $id = $data['id'] ?? null;
                $name = $data['name'] ?? null;
                $namespace = $data['namespace'] ?? null;
                $version = $data['version'] ?? null;
                $parent = $data['parent'] ?? null;
                if (array_key_exists('assets', $data)) {
                    throw new \InvalidArgumentException("Direct theme assets are forbidden; use config/libraries.yaml: {$manifest}");
                }
                $libraries = $data['libraries'] ?? [];
                $regions = $this->validateRegions($data['regions'] ?? [], $manifest);
                if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id) || !is_string($name) || trim($name) === '' || !is_string($namespace) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $namespace) || !is_string($version) || trim($version) === '' || $parent !== null && (!is_string($parent) || !preg_match('/^[a-z][a-z0-9_]*$/', $parent)) || !is_array($libraries) || !array_is_list($libraries) || array_diff(array_keys($data), ['id', 'name', 'namespace', 'version', 'parent', 'libraries', 'regions']) !== []) {
                    throw new \InvalidArgumentException("Invalid theme metadata: {$manifest}");
                }
                foreach ($libraries as $library) {
                    if (!is_string($library) || !preg_match('#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#', $library)) {
                        throw new \InvalidArgumentException("Invalid global theme library in {$manifest}");
                    }
                }
                if (isset($themes[$id])) {
                    throw new \InvalidArgumentException("Duplicate theme ID: {$id}");
                }
                $namespaceKey = strtolower($namespace);
                if (isset($namespaces[$namespaceKey])) {
                    throw new \InvalidArgumentException("Duplicate theme namespace: {$namespace}");
                }
                $themes[$id] = new Theme($id, $namespace, $path, $parent, $libraries, $regions);
                $namespaces[$namespaceKey] = true;
            }
        }

        foreach (array_keys($themes) as $id) {
            $this->lineageFor($id, $themes);
        }
        foreach (array_keys($themes) as $id) {
            $this->regions($id, $themes);
        }
        if ($this->useCache) {
            $this->writeCache($themes);
        }

        return $this->discovered = $themes;
    }

    public function registerNamespaces(): void
    {
        foreach ($this->discover() as $theme) {
            $this->loader->addPsr4('Theme\\' . $theme->namespace . '\\', $theme->path . '/src');
        }
    }

    public function select(string $activeId, string $adminId): void
    {
        $themes = $this->discover();
        if (!isset($themes[$activeId])) {
            throw new \InvalidArgumentException("Active theme not found: {$activeId}");
        }
        if (!isset($themes[$adminId])) {
            throw new \InvalidArgumentException("Administration theme not found: {$adminId}");
        }
        $this->active = $themes[$activeId];
        $this->admin = $themes[$adminId];
    }

    public function active(): Theme
    {
        return $this->active ?? throw new \LogicException('No active theme selected.');
    }

    public function administration(): Theme
    {
        return $this->admin ?? throw new \LogicException('No administration theme selected.');
    }

    /** @return list<Theme> Active theme first, followed by its ancestors. */
    public function activeLineage(): array
    {
        return $this->lineageFor($this->active()->id, $this->discover());
    }

    /** @return list<Theme> Administration theme first, followed by its ancestors. */
    public function administrationLineage(): array
    {
        return $this->lineageFor($this->administration()->id, $this->discover());
    }

    /** @return list<string> */
    public function globalLibraries(): array
    {
        $libraries = [];
        foreach (array_reverse($this->activeLineage()) as $theme) {
            array_push($libraries, ...$theme->libraries);
        }

        return $libraries;
    }

    /** @return list<string> */
    public function administrationLibraries(): array
    {
        $libraries = [];
        foreach (array_reverse($this->administrationLineage()) as $theme) {
            array_push($libraries, ...$theme->libraries);
        }
        return $libraries;
    }

    /** @param array<string, Theme> $themes
     *  @return list<Theme>
     */
    private function lineageFor(string $id, array $themes): array
    {
        $lineage = [];
        $seen = [];
        while ($id !== '') {
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException("Circular theme inheritance involving {$id}");
            }
            if (!isset($themes[$id])) {
                throw new \InvalidArgumentException("Theme parent not found: {$id}");
            }
            $seen[$id] = true;
            $theme = $themes[$id];
            $lineage[] = $theme;
            $id = $theme->parentId ?? '';
        }

        return $lineage;
    }

    /** @param array<string, Theme>|null $themes
     *  @return array<string, string|null>
     */
    public function regions(string $id, ?array $themes = null): array
    {
        $regions = [];
        foreach (array_reverse($this->lineageFor($id, $themes ?? $this->discover())) as $theme) {
            $regions = array_replace($regions, $theme->regions);
        }
        foreach ($regions as $name => $parent) {
            $seen = [$name => true];
            while ($parent !== null) {
                if (!array_key_exists($parent, $regions)) {
                    throw new \InvalidArgumentException("Region {$name} has unknown parent {$parent}.");
                }
                if (isset($seen[$parent])) {
                    throw new \InvalidArgumentException("Circular region nesting involving {$name}.");
                }
                $seen[$parent] = true;
                $parent = $regions[$parent];
            }
        }
        return $regions;
    }

    /** @return array<string, string|null> */
    private function validateRegions(mixed $value, string $source): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new \InvalidArgumentException("Invalid regions in {$source}.");
        }
        $regions = [];
        foreach ($value as $id => $definition) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id) || !is_array($definition) || array_diff(array_keys($definition), ['parent']) !== []) {
                throw new \InvalidArgumentException("Invalid region in {$source}.");
            }
            $parent = $definition['parent'] ?? null;
            if ($parent !== null && (!is_string($parent) || !preg_match('/^[a-z][a-z0-9_]*$/', $parent))) {
                throw new \InvalidArgumentException("Invalid region parent in {$source}.");
            }
            $regions[$id] = $parent;
        }
        return $regions;
    }

    /** @return list<string> */
    private function roots(): array
    {
        return [
            $this->rootPath . '/themes/core',
            $this->rootPath . '/themes/contrib',
            $this->rootPath . '/themes/custom',
        ];
    }

    /** @return array<string, Theme>|null */
    private function readCache(): ?array
    {
        $path = $this->rootPath . '/storage/cache/themes.json';
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        $cache = $contents === false ? null : json_decode($contents, true);
        if (!is_array($cache) || ($cache['version'] ?? null) !== 3 || ($cache['roots'] ?? null) !== $this->rootStamps() || !is_array($cache['themes'] ?? null) || !array_is_list($cache['themes'])) {
            return null;
        }
        $themes = [];
        foreach ($cache['themes'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['namespace'], $item['path'], $item['libraries'], $item['stamp']) || !is_string($item['id']) || !preg_match('/^[a-z][a-z0-9_]*$/', $item['id']) || !is_string($item['namespace']) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $item['namespace']) || !is_string($item['path']) || !in_array(dirname($item['path']), $this->roots(), true) || ($item['parent'] ?? null) !== null && (!is_string($item['parent']) || !preg_match('/^[a-z][a-z0-9_]*$/', $item['parent'])) || !is_array($item['libraries']) || !array_is_list($item['libraries']) || $item['stamp'] !== $this->themeStamp($item['path']) || isset($themes[$item['id']])) {
                return null;
            }
            foreach ($item['libraries'] as $library) {
                if (!is_string($library) || !preg_match('#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#', $library)) {
                    return null;
                }
            }
            $themes[$item['id']] = new Theme($item['id'], $item['namespace'], $item['path'], $item['parent'] ?? null, $item['libraries'], $item['regions'] ?? []);
        }
        try {
            foreach (array_keys($themes) as $id) {
                $this->lineageFor($id, $themes);
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $themes;
    }

    /** @param array<string, Theme> $themes */
    private function writeCache(array $themes): void
    {
        $directory = $this->rootPath . '/storage/cache';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create theme cache directory: {$directory}");
        }
        $items = [];
        foreach ($themes as $theme) {
            $items[] = [
                'id' => $theme->id,
                'namespace' => $theme->namespace,
                'path' => $theme->path,
                'parent' => $theme->parentId,
                'libraries' => $theme->libraries,
                'regions' => $theme->regions,
                'stamp' => $this->themeStamp($theme->path),
            ];
        }
        $contents = json_encode(['version' => 3, 'roots' => $this->rootStamps(), 'themes' => $items], JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, 'themes-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false || !rename($temporary, $directory . '/themes.json')) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Cannot write theme discovery cache.');
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
    private function themeStamp(string $path): array
    {
        return [$this->stamp($path), $this->stamp($path . '/theme.yaml')];
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
