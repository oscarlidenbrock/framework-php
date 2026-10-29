<?php

declare(strict_types=1);

namespace Module\User;

use Framework\Config\ConfigManager;
use Framework\Database\DatabaseManager;
use Framework\Database\DatabaseRegistry;
use Framework\Security\PermissionRegistry;

final class AccountManager
{
    public function __construct(
        private readonly DatabaseRegistry $databases,
        private readonly ConfigManager $config,
        private readonly PermissionRegistry $permissions,
    ) {
        $users = $config->get('users');
        if ($users !== null && (!is_array($users) || array_diff(array_keys($users), ['connection', 'registration_enabled']) !== [] || array_key_exists('connection', $users) && (!is_string($users['connection']) || !preg_match('/^[a-z][a-z0-9_]*$/', $users['connection'])) || array_key_exists('registration_enabled', $users) && !is_bool($users['registration_enabled']))) {
            throw new \InvalidArgumentException('Invalid user configuration.');
        }
    }

    public function database(): DatabaseManager
    {
        $name = $this->config->get('users.connection');
        if ($name !== null && !is_string($name)) {
            throw new \InvalidArgumentException('Invalid user database connection.');
        }
        return $this->databases->manager($name);
    }

    public function register(string $email, string $password): Account
    {
        if (!$this->registrationEnabled()) {
            throw new \LogicException('Public registration is disabled.');
        }
        return $this->create($email, $password);
    }

    public function registrationEnabled(): bool
    {
        return $this->config->get('users.registration_enabled') === true;
    }

    public function createFirstAdministrator(string $email, string $password): Account
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('The first administrator must be created from CLI.');
        }
        $this->permissions->require('admin.access');
        $this->permissions->require('users.manage');
        $db = $this->database();
        return $db->transaction(function () use ($email, $password, $db): Account {
            if ((int) $db->fetchValue('SELECT COUNT(*) FROM users') !== 0) {
                throw new \LogicException('The first administrator can only be created before other accounts.');
            }
            $account = $this->create($email, $password);
            $db->execute('INSERT INTO roles (name) VALUES (:name)', ['name' => 'administrator']);
            $role = (int) $db->connection()->lastInsertId();
            $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $account->id(), 'role' => $role]);
            return $account;
        });
    }

    private function create(string $email, string $password): Account
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || strlen($password) < 12) {
            throw new \InvalidArgumentException('Invalid email or password.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = gmdate('Y-m-d H:i:s.u');
        $db = $this->database();
        $db->execute('INSERT INTO users (email, password_hash, active, created_at, updated_at) VALUES (:email, :hash, :active, :created, :updated)', [
            'email' => $email, 'hash' => $hash, 'active' => 1, 'created' => $now, 'updated' => $now,
        ]);
        return new Account((int) $db->connection()->lastInsertId(), $email, true);
    }

    public function find(int $id): ?Account
    {
        $row = $this->database()->queryOne('SELECT id, email, active FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : new Account((int) $row['id'], $row['email'], (bool) $row['active']);
    }

    /** @return array<string, mixed>|null */
    public function credentials(string $email): ?array
    {
        return $this->database()->queryOne('SELECT id, email, active, password_hash FROM users WHERE email = :email', ['email' => strtolower(trim($email))]);
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        if (!$this->permissions->has($permission)) {
            return false;
        }
        return $this->database()->queryOne('SELECT 1 FROM users u JOIN user_roles ur ON u.id = ur.user_id JOIN roles r ON ur.role_id = r.id LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.permission = :permission WHERE u.id = :user AND u.active = 1 AND (r.name = :administrator OR rp.permission IS NOT NULL)', ['user' => $userId, 'permission' => $permission, 'administrator' => 'administrator']) !== null;
    }
}
