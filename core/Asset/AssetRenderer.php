<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetRenderer
{
    /** @param list<AssetDefinition> $assets */
    public function stylesheets(array $assets): string
    {
        $html = [];
        foreach ($assets as $asset) {
            if ($asset->type !== 'css' || $asset->url === null) {
                throw new \InvalidArgumentException('Expected a resolved stylesheet.');
            }
            $html[] = '<link rel="stylesheet" href="' . $this->escape($asset->url) . '"' . $this->attributes($asset->attributes) . '>';
        }

        return implode("\n", $html);
    }

    /** @param list<AssetDefinition> $assets */
    public function scripts(array $assets, string $position): string
    {
        if (!in_array($position, ['head', 'body'], true)) {
            throw new \InvalidArgumentException('Invalid script position.');
        }
        $html = [];
        foreach ($assets as $asset) {
            if ($asset->type !== 'js' || $asset->url === null) {
                throw new \InvalidArgumentException('Expected a resolved script.');
            }
            if ($asset->position === $position) {
                $html[] = '<script src="' . $this->escape($asset->url) . '"' . $this->attributes($asset->attributes) . '></script>';
            }
        }

        return implode("\n", $html);
    }

    /** @param array<string, string|int|bool> $attributes */
    private function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === false) {
                continue;
            }
            $html .= ' ' . $name;
            if ($value !== true) {
                $html .= '="' . $this->escape((string) $value) . '"';
            }
        }

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
