<?php

declare(strict_types=1);

namespace Framework\Runtime;

final readonly class RuntimePaths
{
    public function __construct(private string $rootPath, private string $workspace)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $workspace)) {
            throw new \InvalidArgumentException('Invalid workspace name.');
        }
    }

    public function cache(string $relative = ''): string
    {
        return $this->path('cache', $relative);
    }

    public function logs(string $relative = ''): string
    {
        return $this->path('logs', $relative);
    }

    private function path(string $kind, string $relative): string
    {
        if ($relative !== '' && (!preg_match('#^[a-zA-Z0-9_./-]+$#', $relative) || in_array('..', explode('/', $relative), true))) {
            throw new \InvalidArgumentException('Invalid runtime path.');
        }
        $base = $this->rootPath . '/var/' . $kind . '/' . $this->workspace;
        if (is_link($this->rootPath . '/var') || is_link($this->rootPath . '/var/' . $kind) || is_link($base)) {
            throw new \RuntimeException('Workspace runtime directory cannot be a symlink.');
        }
        return $base . ($relative === '' ? '' : '/' . $relative);
    }
}
