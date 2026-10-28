<?php

declare(strict_types=1);

namespace Framework\Asset;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetManager $assets,
        private readonly AssetRenderer $renderer = new AssetRenderer(),
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('assets_css', $this->stylesheets(...), ['is_safe' => ['html']]),
            new TwigFunction('assets_js', $this->scripts(...), ['is_safe' => ['html']]),
        ];
    }

    public function stylesheets(): string
    {
        return $this->renderer->stylesheets($this->assets->assets('css'));
    }

    public function scripts(string $position): string
    {
        return $this->renderer->scripts($this->assets->assets('js'), $position);
    }
}
