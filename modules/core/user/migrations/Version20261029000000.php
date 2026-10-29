<?php

declare(strict_types=1);

namespace Module\User\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('email', 'string', ['length' => 255]);
        $users->addColumn('password_hash', 'string', ['length' => 255]);
        $users->addColumn('active', 'boolean');
        $users->addColumn('created_at', 'datetime');
        $users->addColumn('updated_at', 'datetime');
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email'], 'uniq_users_email');

        $roles = $schema->createTable('roles');
        $roles->addColumn('id', 'integer', ['autoincrement' => true]);
        $roles->addColumn('name', 'string', ['length' => 100]);
        $roles->setPrimaryKey(['id']);
        $roles->addUniqueIndex(['name'], 'uniq_roles_name');

        $userRoles = $schema->createTable('user_roles');
        $userRoles->addColumn('user_id', 'integer');
        $userRoles->addColumn('role_id', 'integer');
        $userRoles->setPrimaryKey(['user_id', 'role_id']);
        $userRoles->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $userRoles->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE']);

        $grants = $schema->createTable('role_permissions');
        $grants->addColumn('role_id', 'integer');
        $grants->addColumn('permission', 'string', ['length' => 120]);
        $grants->setPrimaryKey(['role_id', 'permission']);
        $grants->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE']);

        $sessions = $schema->createTable('auth_sessions');
        $sessions->addColumn('id_hash', 'string', ['length' => 64]);
        $sessions->addColumn('user_id', 'integer', ['notnull' => false]);
        $sessions->addColumn('csrf_token', 'string', ['length' => 64]);
        $sessions->addColumn('expires_at', 'bigint');
        $sessions->setPrimaryKey(['id_hash']);
        $sessions->addIndex(['expires_at'], 'idx_auth_sessions_expiry');
        $sessions->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        $attempts = $schema->createTable('login_attempts');
        $attempts->addColumn('key_hash', 'string', ['length' => 64]);
        $attempts->addColumn('attempts', 'integer');
        $attempts->addColumn('window_start', 'bigint');
        $attempts->setPrimaryKey(['key_hash']);
    }

    public function down(Schema $schema): void
    {
        throw new \LogicException('User accounts must not be dropped automatically.');
    }
}
