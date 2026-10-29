<?php

declare(strict_types=1);

namespace Framework\Security;

interface CsrfInterface
{
    public function csrfToken(): string;

    public function validateCsrf(mixed $token): bool;
}
