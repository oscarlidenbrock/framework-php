<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetRegistry
{
    /** @var array<string, AssetDefinition> */
    private array $byUrl = [];
    /** @var array{css: list<AssetDefinition>, js: list<AssetDefinition>, fonts: list<AssetDefinition>} */
    private array $items = ['css' => [], 'js' => [], 'fonts' => []];

    public function add(AssetDefinition $asset): void
    {
        $url = $asset->url ?? throw new \LogicException('Asset URL is not resolved.');
        if (isset($this->byUrl[$url])) {
            if ($this->byUrl[$url]->configuration() !== $asset->configuration()) {
                throw new \InvalidArgumentException("Conflicting asset configuration for {$url}");
            }

            return;
        }
        $this->byUrl[$url] = $asset;
        $this->items[$asset->type][] = $asset;
    }

    /** @return list<AssetDefinition> */
    public function ofType(string $type): array
    {
        return $this->items[$type] ?? throw new \InvalidArgumentException("Unknown asset type: {$type}");
    }
}
