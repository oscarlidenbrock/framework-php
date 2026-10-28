<?php

declare(strict_types=1);

namespace Framework\Module;

final readonly class Module
{
    /** @param list<string> $requires
     *  @param array<string, mixed> $database
     */
    public function __construct(
        public string $id,
        public string $namespace,
        public string $path,
        public array $requires,
        public array $database = [],
    ) {
    }
}
