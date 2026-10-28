<?php

declare(strict_types=1);

namespace Framework\Config;

use Symfony\Component\Yaml\Yaml;

final class ConfigManager
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{enabled_modules: list<string>, module_discovery_cache: bool, theme_discovery_cache: bool, active_theme: string, admin_theme: string, twig_cache: bool} */
    public function load(): array
    {
        if (!is_file($this->path)) {
            throw new \InvalidArgumentException("Missing application configuration: {$this->path}");
        }

        $config = Yaml::parseFile($this->path);
        if (!is_array($config) || array_diff(array_keys($config), ['enabled_modules', 'module_discovery_cache', 'theme_discovery_cache', 'active_theme', 'admin_theme', 'twig_cache']) !== []) {
            throw new \InvalidArgumentException("Unknown or invalid application configuration: {$this->path}");
        }

        $enabled = $config['enabled_modules'] ?? null;
        $cache = $config['module_discovery_cache'] ?? false;
        $themeCache = $config['theme_discovery_cache'] ?? false;
        $activeTheme = $config['active_theme'] ?? 'default';
        $adminTheme = $config['admin_theme'] ?? 'default';
        $twigCache = $config['twig_cache'] ?? false;
        if (!is_array($enabled) || !array_is_list($enabled) || !is_bool($cache) || !is_bool($themeCache) || !is_bool($twigCache) || !is_string($activeTheme) || !preg_match('/^[a-z][a-z0-9_]*$/', $activeTheme) || !is_string($adminTheme) || !preg_match('/^[a-z][a-z0-9_]*$/', $adminTheme)) {
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
        ];
    }
}
