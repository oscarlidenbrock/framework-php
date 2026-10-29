<?php

declare(strict_types=1);

namespace Framework\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface RequestSecurityInterface extends AuthorizationInterface
{
    public function active(): bool;

    public function begin(Request $request): void;

    public function finish(Response $response, Request $request): void;

    public function end(): void;
}
