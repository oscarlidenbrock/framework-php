<?php

declare(strict_types=1);

namespace Framework\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigManager
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $files = null;

    public function __construct(
        private readonly string $directory,
        private readonly string $workspace,
        private readonly ?string $appPath = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $workspace)) {
            throw new \InvalidArgumentException('Invalid workspace name.');
        }
    }

    public function get(string $key): mixed
    {
        $segments = explode('.', $key);
        $name = array_shift($segments);
        $file = $this->file($name . '.yaml');
        if ($file === null) {
            return null;
        }
        $value = array_key_exists($name, $file) ? $file[$name] : $file;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    public function file(string $name): ?array
    {
        if (!preg_match('#^[a-z][a-z0-9_/-]*\.ya?ml$#', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Invalid configuration file name.');
        }
        $this->discover();

        return $this->files[$name] ?? null;
    }

    /** @return array{enabled_modules: list<string>, module_discovery_cache: bool, theme_discovery_cache: bool, active_theme: string, admin_theme: string, twig_cache: bool, debug: bool} */
    public function load(): array
    {
        $config = $this->file('app.yaml');
        if ($config === null || array_diff(array_keys($config), ['enabled_modules', 'module_discovery_cache', 'theme_discovery_cache', 'active_theme', 'admin_theme', 'twig_cache', 'debug']) !== []) {
            throw new \InvalidArgumentException('Missing or invalid application configuration.');
        }
        $modules = $this->file('modules.yaml');
        if ($modules !== null && array_diff(array_keys($modules), ['enabled_modules']) !== []) {
            throw new \InvalidArgumentException('Invalid modules configuration.');
        }

        $enabled = $modules !== null && array_key_exists('enabled_modules', $modules) ? $modules['enabled_modules'] : ($config['enabled_modules'] ?? null);
        $cache = $config['module_discovery_cache'] ?? false;
        $themeCache = $config['theme_discovery_cache'] ?? false;
        $activeTheme = $config['active_theme'] ?? 'default';
        $adminTheme = $config['admin_theme'] ?? 'default';
        $twigCache = $config['twig_cache'] ?? false;
        $debug = $config['debug'] ?? false;
        if (!is_array($enabled) || !array_is_list($enabled) || !is_bool($cache) || !is_bool($themeCache) || !is_bool($twigCache) || !is_bool($debug) || !is_string($activeTheme) || !preg_match('/^[a-z][a-z0-9_]*$/', $activeTheme) || !is_string($adminTheme) || !preg_match('/^[a-z][a-z0-9_]*$/', $adminTheme)) {
            throw new \InvalidArgumentException('Invalid module, theme or Twig configuration.');
        }
        foreach ($enabled as $id) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new \InvalidArgumentException('Invalid enabled module ID.');
            }
        }
        if (count($enabled) !== count(array_unique($enabled))) {
            throw new \InvalidArgumentException('Duplicate enabled module ID.');
        }

        return [
            'enabled_modules' => $enabled,
            'module_discovery_cache' => $cache,
            'theme_discovery_cache' => $themeCache,
            'active_theme' => $activeTheme,
            'admin_theme' => $adminTheme,
            'twig_cache' => $twigCache,
            'debug' => $debug,
        ];
    }

    /** @param array<int|string, mixed> $base
     *  @param array<int|string, mixed> $override
     *  @return array<int|string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        if ($override === [] || array_is_list($base) || array_is_list($override)) {
            return $override;
        }
        $merged = $base;
        foreach ($override as $key => $value) {
            $merged[$key] = array_key_exists($key, $base) && is_array($base[$key]) && is_array($value) && !array_is_list($base[$key]) && !array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $merged;
    }

    private function discover(): void
    {
        if ($this->files !== null) {
            return;
        }
        $workspacePath = $this->directory . '/workspaces/' . $this->workspace;
        $root = realpath($this->directory . '/workspaces');
        $realWorkspace = realpath($workspacePath);
        if ($root === false || $realWorkspace === false || !is_dir($realWorkspace) || !str_starts_with($realWorkspace, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Workspace directory does not exist.');
        }
        $files = [];
        foreach ([$this->directory, $workspacePath] as $index => $directory) {
            $realDirectory = realpath($directory);
            $entries = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
            $filtered = new \RecursiveCallbackFilterIterator($entries, static fn (\SplFileInfo $entry): bool => $index !== 0 || $entry->getPathname() !== $directory . '/workspaces');
            $iterator = new \RecursiveIteratorIterator($filtered);
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || !preg_match('/\.ya?ml$/', $entry->getFilename())) {
                    continue;
                }
                $relative = substr($entry->getPathname(), strlen($directory) + 1);
                if (str_starts_with($relative, 'workspaces/') || $relative === 'workspaces.yaml') {
                    continue;
                }
                $realFile = realpath($entry->getPathname());
                if ($realFile === false || !str_starts_with($realFile, $realDirectory . DIRECTORY_SEPARATOR)) {
                    throw new \InvalidArgumentException("Configuration file escapes its directory: {$relative}");
                }
                $document = $this->parse($entry->getPathname(), $relative);
                $files[$relative] = $index === 1 && isset($files[$relative]) ? self::merge($files[$relative], $document) : $document;
            }
        }
        if ($this->appPath !== null) {
            $document = $this->parse($this->appPath, 'app.yaml override');
            $files['app.yaml'] = self::merge($files['app.yaml'] ?? [], $document);
        }
        $this->files = $files;
    }

    /** @return array<string, mixed> */
    private function parse(string $path, string $label): array
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException("Configuration file is not readable: {$label}");
        }
        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException("Invalid YAML in {$label}: {$exception->getMessage()}", previous: $exception);
        }
        if (!is_array($document) || $document !== [] && array_is_list($document)) {
            throw new \InvalidArgumentException("Configuration file must contain a mapping: {$label}");
        }

        return $document;
    }
}
