<?php

declare(strict_types=1);

namespace Framework\Security;

interface AuthenticationInterface
{
    public function identity(): ?IdentityInterface;
}
