<?php

declare(strict_types=1);

namespace Module\Admin;

use Framework\Security\AuthorizationInterface;

final class Dashboard
{
    public function __construct(private readonly AuthorizationInterface $authorization)
    {
    }

    public function view(): string
    {
        $this->authorization->requirePermission('admin.access');
        return $this->authorization->identity()?->email() ?? throw new \LogicException('Authenticated identity is missing.');
    }
}
