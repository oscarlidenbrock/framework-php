<?php

declare(strict_types=1);

namespace Framework\Asset;

use Framework\Module\Module;
use Framework\Theme\Theme;
use Symfony\Component\Yaml\Yaml;

final class LibraryRegistry
{
    /** @var array<string, Library> */
    private array $libraries = [];
    /** @var array<string, true> */
    private array $owners = [];

    /** @param list<Module> $enabledModules
     *  @param list<Theme> $themes
     */
    public function __construct(
        private readonly string $rootPath,
        array $enabledModules,
        array $themes,
        private readonly AssetNormalizer $normalizer = new AssetNormalizer(),
    ) {
        foreach ($enabledModules as $module) {
            $this->registerOwner($module->id);
        }
        foreach ($themes as $theme) {
            $this->registerOwner($theme->id);
        }
        foreach ($enabledModules as $module) {
            $this->load($module->id, 'module', $module->path);
        }
        foreach ($themes as $theme) {
            $this->load($theme->id, 'theme', $theme->path);
        }
        foreach ($this->libraries as $library) {
            foreach ($library->dependencies as $dependency) {
                if (!isset($this->libraries[$dependency])) {
                    throw new \InvalidArgumentException("Library {$library->id} requires unavailable library {$dependency}");
                }
            }
        }
    }

    public function get(string $id): Library
    {
        return $this->libraries[$id] ?? throw new \InvalidArgumentException("Library not found or unavailable: {$id}");
    }

    /** @return array<string, Library> */
    public function all(): array
    {
        return $this->libraries;
    }

    public function publish(string $ownerType): void
    {
        if (!in_array($ownerType, ['module', 'theme'], true)) {
            throw new \InvalidArgumentException("Unknown library owner type: {$ownerType}");
        }
        $publisher = new AssetPublisher();
        foreach ($this->libraries as $library) {
            if ($library->ownerType !== $ownerType) {
                continue;
            }
            $publisher->publish(
                $library->ownerPath . '/assets',
                $this->rootPath . '/public/assets/' . ($ownerType === 'module' ? 'modules' : 'themes') . '/' . explode('/', $library->id, 2)[0],
                $this->rootPath . '/public',
                $library->assets,
            );
        }
    }

    private function registerOwner(string $id): void
    {
        if (isset($this->owners[$id])) {
            throw new \InvalidArgumentException("Extension ID shared by module and theme: {$id}");
        }
        $this->owners[$id] = true;
    }

    private function load(string $ownerId, string $ownerType, string $ownerPath): void
    {
        $path = $ownerPath . '/config/libraries.yaml';
        if (!is_file($path)) {
            return;
        }
        $definitions = Yaml::parseFile($path);
        if (!is_array($definitions) || array_is_list($definitions) && $definitions !== []) {
            throw new \InvalidArgumentException("Libraries file must be a mapping: {$path}");
        }
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name) || !is_array($definition) || array_is_list($definition) && $definition !== [] || array_diff(array_keys($definition), ['version', 'dependencies', 'css', 'js']) !== []) {
                throw new \InvalidArgumentException("Invalid library definition in {$path}");
            }
            $id = $ownerId . '/' . $name;
            $version = $definition['version'] ?? null;
            $dependencies = $definition['dependencies'] ?? [];
            if ($version !== null && (!is_string($version) || trim($version) === '') || !is_array($dependencies) || !array_is_list($dependencies)) {
                throw new \InvalidArgumentException("Invalid library metadata: {$id}");
            }
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || !preg_match('#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#', $dependency)) {
                    throw new \InvalidArgumentException("Invalid dependency in library {$id}");
                }
            }
            if (count($dependencies) !== count(array_unique($dependencies))) {
                throw new \InvalidArgumentException("Duplicate dependency in library {$id}");
            }
            $assets = $this->normalizer->normalizeGroups([
                'css' => array_key_exists('css', $definition) ? $definition['css'] : [],
                'js' => array_key_exists('js', $definition) ? $definition['js'] : [],
            ], $id);
            $resolved = ['css' => [], 'js' => []];
            foreach (['css', 'js'] as $type) {
                foreach ($assets[$type] as $asset) {
                    $file = $ownerPath . '/assets/' . $asset->path;
                    $root = realpath($ownerPath . '/assets');
                    $real = realpath($file);
                    if ($root === false || $real === false || !is_file($file) || is_link($file) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                        throw new \InvalidArgumentException("Library asset missing or unsafe: {$id} {$asset->path}");
                    }
                    $url = '/assets/' . ($ownerType === 'module' ? 'modules' : 'themes') . '/' . $ownerId . '/' . $asset->path;
                    $resolved[$type][] = $asset->withUrl($url);
                }
            }
            $this->libraries[$id] = new Library($id, $ownerType, $ownerPath, $dependencies, $resolved);
        }
    }
}
