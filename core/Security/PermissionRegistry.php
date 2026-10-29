<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Module\Module;
use Symfony\Component\Yaml\Yaml;

final class PermissionRegistry
{
    /** @var array<string, string> */
    private array $owners = [];

    /** @param list<Module> $modules */
    public function __construct(array $modules)
    {
        foreach ($modules as $module) {
            $file = $module->path . '/config/permissions.yaml';
            if (!is_file($file)) {
                continue;
            }
            $permissions = Yaml::parseFile($file);
            if (!is_array($permissions) || array_is_list($permissions)) {
                throw new \InvalidArgumentException("Invalid permissions file for module {$module->id}.");
            }
            foreach ($permissions as $id => $label) {
                if (!is_string($id) || strlen($id) > 120 || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $id) || !is_string($label) || trim($label) === '' || isset($this->owners[$id])) {
                    throw new \InvalidArgumentException("Invalid or duplicate permission: {$id}");
                }
                $this->owners[$id] = $module->id;
            }
        }
    }

    public function has(string $permission): bool
    {
        return isset($this->owners[$permission]);
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->owners);
    }

    public function require(string $permission): void
    {
        if (!$this->has($permission)) {
            throw new \InvalidArgumentException("Unknown permission: {$permission}");
        }
    }
}
