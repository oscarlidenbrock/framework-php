<?php

declare(strict_types=1);

namespace Module\Example;

use Framework\Block\BlockInterface;

final class MessageBlock implements BlockInterface
{
    public function id(): string
    {
        return 'example.message';
    }

    public function label(): string
    {
        return 'Example message';
    }

    public function template(): string
    {
        return '@example/block.html.twig';
    }

    public function variables(): array
    {
        return ['message' => 'A block contributed by the example module.'];
    }

    public function libraries(): array
    {
        return [];
    }
}
