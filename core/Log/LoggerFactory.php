<?php

declare(strict_types=1);

namespace Framework\Log;

use Framework\Runtime\RuntimePaths;
use Psr\Log\LoggerInterface;

final readonly class LoggerFactory
{
    public function __construct(private RuntimePaths $paths, private int $maxBytes = 1048576, private int $retention = 5)
    {
        if ($maxBytes < 4096 || $retention < 1 || $retention > 30) {
            throw new \InvalidArgumentException('Invalid logging rotation configuration.');
        }
    }

    public function channel(string $name): LoggerInterface
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid log channel.');
        }
        return new FileLogger($this->paths->logs($name . '.log'), $name, $this->maxBytes, $this->retention);
    }
}
