<?php

declare(strict_types=1);

namespace Framework\Block;

use Framework\Asset\AssetManager;
use Framework\Template\TemplateManager;
use Framework\Theme\ThemeManager;
use Twig\Markup;

final class RegionRenderer
{
    public function __construct(private readonly ThemeManager $themes, private readonly BlockRegistry $blocks, private readonly AssetManager $assets, private readonly TemplateManager $templates, private readonly \Closure $placements, private readonly bool $administration = false)
    {
    }

    /** @return array<string, Markup> */
    public function render(): array
    {
        $theme = $this->administration ? $this->themes->administration() : $this->themes->active();
        $regions = $this->themes->regions($theme->id);
        $placements = ($this->placements)($theme->id);
        $byRegion = [];
        foreach ($placements as $placement) {
            if (!$placement['enabled'] || !array_key_exists($placement['region'], $regions)) {
                continue;
            }
            $block = $this->blocks->get($placement['block']);
            if ($block === null) {
                continue;
            }
            $byRegion[$placement['region']][] = [$placement['weight'], $placement['block'], $block];
            foreach ($block->libraries() as $library) {
                $this->assets->attachLibrary($library);
            }
        }
        $result = [];
        $render = function (string $id) use (&$render, &$result, $regions, $byRegion): Markup {
            if (isset($result[$id])) {
                return $result[$id];
            }
            $items = $byRegion[$id] ?? [];
            usort($items, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $html = '';
            foreach ($items as [, , $block]) {
                $html .= $this->templates->renderFragment($block->template(), $block->variables());
            }
            $context = ['content' => new Markup($html, 'UTF-8')];
            foreach ($regions as $child => $parent) {
                if ($parent === $id) {
                    $context[$child] = $render($child);
                }
            }
            $template = '@theme/regions/' . $id . '.html.twig';
            $html = $this->templates->hasTemplate($template) ? $this->templates->renderFragment($template, $context) : $html . implode('', array_map(static fn (Markup $item): string => (string) $item, array_filter($context, static fn (mixed $item, string $key): bool => $key !== 'content', ARRAY_FILTER_USE_BOTH)));
            return $result[$id] = new Markup($html, 'UTF-8');
        };
        foreach (array_keys($regions) as $id) {
            $render($id);
        }
        return $result;
    }
}
