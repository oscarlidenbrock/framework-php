<?php

declare(strict_types=1);

namespace Module\Site\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029010000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $config = $schema->createTable('config');
        $config->addColumn('workspace', 'string', ['length' => 64]);
        $config->addColumn('setting_key', 'string', ['length' => 100]);
        $config->addColumn('value', 'text');
        $config->addColumn('updated_at', 'datetime');
        $config->setPrimaryKey(['workspace', 'setting_key']);
    }

    public function down(Schema $schema): void
    {
        throw new \LogicException('Site settings must not be dropped automatically.');
    }
}
