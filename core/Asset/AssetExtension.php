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
            new TwigFunction('assets', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    public function render(string $type, string $slot): string
    {
        return $this->renderer->render($this->assets->assets($type), $type, $slot);
    }
}
