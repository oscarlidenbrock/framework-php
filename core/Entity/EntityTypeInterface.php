<?php

declare(strict_types=1);

namespace Framework\Entity;

interface EntityTypeInterface
{
    public function id(): string;

    public function connectionName(): string;
}
