<?php

declare(strict_types=1);

namespace Framework\Theme;

final readonly class Theme
{
    public function __construct(
        public string $id,
        public string $namespace,
        public string $path,
        public ?string $parentId,
        /** @var list<string> */
        public array $libraries,
    ) {
    }
}
