<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetPublisher
{
    /** @param array{css: list<AssetDefinition>, js: list<AssetDefinition>} $assets */
    public function publish(string $sourceRoot, string $destinationRoot, string $publicRoot, array $assets): void
    {
        $realRoot = realpath($sourceRoot);
        $realPublicRoot = realpath($publicRoot);
        if ($realPublicRoot === false || !is_dir($realPublicRoot)) {
            throw new \InvalidArgumentException("Public document root not found: {$publicRoot}");
        }
        foreach (['css', 'js'] as $type) {
            foreach ($assets[$type] as $asset) {
                $source = $sourceRoot . '/' . $asset->path;
                $realSource = realpath($source);
                if ($realRoot === false || $realSource === false || !is_file($source) || is_link($source) || !str_starts_with($realSource, $realRoot . DIRECTORY_SEPARATOR)) {
                    throw new \InvalidArgumentException("Asset missing or linked: {$source}");
                }
                $destination = $destinationRoot . '/' . $asset->path;
                $directory = dirname($destination);
                $this->ensurePublicDirectory($directory, $publicRoot, $realPublicRoot);
                if (is_link($destination) || !copy($source, $destination)) {
                    throw new \RuntimeException("Cannot publish asset: {$source}");
                }
            }
        }
    }

    private function ensurePublicDirectory(string $directory, string $publicRoot, string $realPublicRoot): void
    {
        if (!str_starts_with($directory, $publicRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Asset destination is outside public: {$directory}");
        }
        $current = $publicRoot;
        foreach (explode(DIRECTORY_SEPARATOR, substr($directory, strlen($publicRoot) + 1)) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \RuntimeException("Linked public asset directory: {$current}");
            }
            if (!is_dir($current) && !mkdir($current, 0775) && !is_dir($current)) {
                throw new \RuntimeException("Cannot create public asset directory: {$current}");
            }
            $real = realpath($current);
            if ($real === false || !str_starts_with($real, $realPublicRoot . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException("Unsafe public asset directory: {$current}");
            }
        }
    }
}
