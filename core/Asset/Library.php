<?php

declare(strict_types=1);

namespace Framework\Asset;

final readonly class Library
{
    /** @param list<string> $dependencies
     *  @param array{css: list<AssetDefinition>, js: list<AssetDefinition>, fonts: list<AssetDefinition>} $assets
     */
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerPath,
        public array $dependencies,
        public array $assets,
    ) {
    }
}
