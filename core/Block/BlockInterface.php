<?php

declare(strict_types=1);

namespace Framework\Block;

interface BlockInterface
{
    public function id(): string;

    public function label(): string;

    public function template(): string;

    /** @return array<string, mixed> */
    public function variables(): array;

    /** @return list<string> */
    public function libraries(): array;
}
