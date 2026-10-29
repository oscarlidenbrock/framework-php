<?php

declare(strict_types=1);

namespace Framework\Config;

use Framework\Runtime\RuntimePaths;

final class InstallationSnapshot
{
    public function __construct(private readonly string $rootPath, private readonly string $workspace)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $workspace)) {
            throw new \InvalidArgumentException('Invalid workspace name.');
        }
    }

    /** @return array{enabled_modules: list<string>, active_theme: string, admin_theme: string}|null */
    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read installation snapshot.');
        }
        try {
            $selection = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Invalid installation snapshot.', previous: $exception);
        }
        self::validate($selection);
        return $selection;
    }

    /** @param array{enabled_modules: list<string>, active_theme: string, admin_theme: string} $selection */
    public function write(array $selection): void
    {
        self::validate($selection);
        $directory = dirname($this->path());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create installation snapshot directory.');
        }
        $temporary = tempnam($directory, 'selection-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create installation snapshot.');
        }
        try {
            $json = json_encode($selection, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->path())) {
                throw new \RuntimeException('Cannot write installation snapshot; run installation:refresh.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function remove(): void
    {
        if (is_file($this->path()) && !unlink($this->path())) {
            throw new \RuntimeException('Cannot remove installation snapshot.');
        }
    }

    public static function validate(mixed $selection): void
    {
        if (!is_array($selection) || array_keys($selection) !== ['enabled_modules', 'active_theme', 'admin_theme'] || !is_array($selection['enabled_modules']) || !array_is_list($selection['enabled_modules'])) {
            throw new \InvalidArgumentException('Invalid installation selection.');
        }
        foreach ([$selection['active_theme'], $selection['admin_theme'], ...$selection['enabled_modules']] as $id) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new \InvalidArgumentException('Invalid installation selection ID.');
            }
        }
        if (count($selection['enabled_modules']) !== count(array_unique($selection['enabled_modules']))) {
            throw new \InvalidArgumentException('Duplicate enabled module.');
        }
    }

    private function path(): string
    {
        return (new RuntimePaths($this->rootPath, $this->workspace))->cache('installation.json');
    }
}
