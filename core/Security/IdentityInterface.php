<?php

declare(strict_types=1);

namespace Framework\Security;

interface IdentityInterface
{
    public function id(): int;

    public function email(): string;
}
