<?php

declare(strict_types=1);

namespace Framework\Block;

final class BlockRegistry
{
    /** @var array<string, BlockInterface> */
    private array $blocks = [];

    public function register(BlockInterface $block): void
    {
        $id = $block->id();
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $id) || isset($this->blocks[$id])) {
            throw new \InvalidArgumentException("Invalid or duplicate block ID: {$id}");
        }
        $this->blocks[$id] = $block;
    }

    /** @return array<string, BlockInterface> */
    public function all(): array
    {
        return $this->blocks;
    }

    public function get(string $id): ?BlockInterface
    {
        return $this->blocks[$id] ?? null;
    }
}
