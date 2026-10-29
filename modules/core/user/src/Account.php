<?php

declare(strict_types=1);

namespace Module\User;

use Framework\Security\IdentityInterface;

final class Account implements IdentityInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        public readonly bool $active,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }
}
