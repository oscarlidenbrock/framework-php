<?php

declare(strict_types=1);

namespace Framework\Security;

interface AuthorizationInterface extends AuthenticationInterface
{
    public function can(string $permission): bool;

    public function requirePermission(string $permission): void;
}
