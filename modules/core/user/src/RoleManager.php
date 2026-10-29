<?php

declare(strict_types=1);

namespace Module\User;

use Framework\Security\AuthorizationInterface;
use Framework\Security\PermissionRegistry;

final class RoleManager
{
    public function __construct(
        private readonly AccountManager $accounts,
        private readonly PermissionRegistry $permissions,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    public function create(string $name): void
    {
        $this->authorization->requirePermission('users.manage');
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name)) {
            throw new \InvalidArgumentException('Invalid role name.');
        }
        $this->accounts->database()->execute('INSERT INTO roles (name) VALUES (:name)', ['name' => $name]);
    }

    public function grant(string $role, string $permission): void
    {
        $this->authorization->requirePermission('users.manage');
        $this->permissions->require($permission);
        $roleId = $this->roleId($role);
        $this->accounts->database()->execute('INSERT INTO role_permissions (role_id, permission) VALUES (:role, :permission)', ['role' => $roleId, 'permission' => $permission]);
    }

    public function assign(int $userId, string $role): void
    {
        $this->authorization->requirePermission('users.manage');
        if ($this->accounts->find($userId) === null) {
            throw new \InvalidArgumentException('Unknown user.');
        }
        $roleId = $this->roleId($role);
        $this->accounts->database()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $userId, 'role' => $roleId]);
    }

    private function roleId(string $name): int
    {
        $row = $this->accounts->database()->queryOne('SELECT id FROM roles WHERE name = :name', ['name' => $name]);
        return $row === null ? throw new \InvalidArgumentException("Unknown role: {$name}") : (int) $row['id'];
    }
}
