<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetRenderer
{
    /** @param list<AssetDefinition> $assets */
    public function render(array $assets, string $type, string $slot): string
    {
        if (!in_array($type, ['css', 'js', 'fonts'], true) || !preg_match('/^[a-z][a-z0-9_]*$/', $slot)) {
            throw new \InvalidArgumentException('Invalid asset type or slot.');
        }
        $html = [];
        foreach ($assets as $asset) {
            if ($asset->type !== $type || $asset->url === null) {
                throw new \InvalidArgumentException('Expected a resolved asset of the requested type.');
            }
            if ($asset->position !== $slot) {
                continue;
            }
            $url = $this->escape($asset->url);
            $html[] = match ($type) {
                'css' => '<link rel="stylesheet" href="' . $url . '"' . $this->attributes($asset->attributes) . '>',
                'js' => '<script src="' . $url . '"' . $this->attributes($asset->attributes) . '></script>',
                'fonts' => '<link rel="preload" as="font" href="' . $url . '"' . $this->attributes($asset->attributes) . '>',
            };
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
