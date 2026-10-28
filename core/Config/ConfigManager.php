<?php

declare(strict_types=1);

namespace Framework\Config;

use Symfony\Component\Yaml\Yaml;

final class ConfigManager
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{enabled_modules: list<string>, module_discovery_cache: bool} */
    public function load(): array
    {
        if (!is_file($this->path)) {
            throw new \InvalidArgumentException("Missing application configuration: {$this->path}");
        }

        $config = Yaml::parseFile($this->path);
        if (!is_array($config) || array_diff(array_keys($config), ['enabled_modules', 'module_discovery_cache']) !== []) {
            throw new \InvalidArgumentException("Unknown or invalid application configuration: {$this->path}");
        }

        $enabled = $config['enabled_modules'] ?? null;
        $cache = $config['module_discovery_cache'] ?? false;
        if (!is_array($enabled) || !array_is_list($enabled) || !is_bool($cache)) {
            throw new \InvalidArgumentException('enabled_modules must be a list and module_discovery_cache must be a boolean.');
        }

        foreach ($enabled as $id) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new \InvalidArgumentException('Invalid enabled module ID.');
            }
        }
        if (count($enabled) !== count(array_unique($enabled))) {
            throw new \InvalidArgumentException('Duplicate enabled module ID.');
        }

        return ['enabled_modules' => $enabled, 'module_discovery_cache' => $cache];
    }
}
