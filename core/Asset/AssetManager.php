<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetManager
{
    /** @var array<string, true> */
    private array $attached = [];
    /** @var array{css: list<AssetDefinition>, js: list<AssetDefinition>, fonts: list<AssetDefinition>}|null */
    private ?array $prepared = null;

    /** @param list<string> $globalLibraries */
    public function __construct(
        private readonly LibraryResolver $resolver,
        private readonly array $globalLibraries,
    ) {
    }

    public function attachLibrary(string $id): void
    {
        if ($this->prepared !== null) {
            throw new \LogicException('Libraries must be attached before template rendering.');
        }
        $this->attached[$id] = true;
    }

    /** @param list<string> $libraries */
    public function prepare(array $libraries = []): void
    {
        foreach ($libraries as $id) {
            $this->attachLibrary($id);
        }
        $registry = new AssetRegistry();
        foreach ($this->resolver->resolve([...$this->globalLibraries, ...array_keys($this->attached)]) as $library) {
            foreach (['css', 'js', 'fonts'] as $type) {
                foreach ($library->assets[$type] as $asset) {
                    $registry->add($asset);
                }
            }
        }
        $this->prepared = ['css' => $registry->ofType('css'), 'js' => $registry->ofType('js'), 'fonts' => $registry->ofType('fonts')];
    }

    /** @return list<AssetDefinition> */
    public function assets(string $type): array
    {
        if ($this->prepared === null) {
            throw new \LogicException('Libraries have not been resolved.');
        }

        return $this->prepared[$type] ?? throw new \InvalidArgumentException("Unknown asset type: {$type}");
    }

    public function reset(): void
    {
        $this->attached = [];
        $this->prepared = null;
    }
}
