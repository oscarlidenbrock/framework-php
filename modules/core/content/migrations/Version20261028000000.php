<?php

declare(strict_types=1);

namespace Module\Content\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('content_types');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('namespace', 'string', ['length' => 30]);
        $table->addColumn('label', 'string', ['length' => 255]);
        $table->addColumn('description', 'text', ['notnull' => false]);
        $table->addColumn('module', 'string', ['length' => 40, 'notnull' => false]);
        $table->addColumn('connection_name', 'string', ['length' => 40]);
        $table->addColumn('schema_status', 'string', ['length' => 20]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['namespace'], 'uniq_content_namespace');
    }

    public function down(Schema $schema): void
    {
        throw new \LogicException('Content data must not be dropped automatically.');
    }
}
