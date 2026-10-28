<?php

declare(strict_types=1);

namespace Framework\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class DatabaseManager
{
    private ?Connection $connection = null;
    private ?EntityManagerInterface $entityManager = null;
    private ?DependencyFactory $migrations = null;

    /** @param array<string, mixed> $definition
     *  @param list<string> $mappingPaths
     *  @param array<string, string> $migrationDirectories
     */
    public function __construct(
        public readonly string $name,
        private readonly array $definition,
        private readonly array $mappingPaths,
        private readonly array $migrationDirectories,
        private readonly string $rootPath,
    ) {
    }

    public function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $params = $this->definition['params'] ?? [];
        if (isset($this->definition['url_env'])) {
            $url = getenv($this->definition['url_env']);
            if ($url === false || trim($url) === '') {
                throw new \InvalidArgumentException("Missing database URL environment variable for connection {$this->name}");
            }
            $params['url'] = $url;
        }
        if (isset($params['url'])) {
            $url = $params['url'];
            if (!is_string($url)) {
                throw new \InvalidArgumentException("Database URL must be a string for connection {$this->name}");
            }
            unset($params['url']);
            $parser = new DsnParser(['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']);
            $params = array_replace($params, $parser->parse($url));
        }

        return $this->connection = DriverManager::getConnection($params);
    }

    public function entityManager(): EntityManagerInterface
    {
        if (!$this->definition['orm']) {
            throw new \LogicException("ORM is disabled for database connection {$this->name}");
        }
        if ($this->entityManager !== null && $this->entityManager->isOpen()) {
            return $this->entityManager;
        }
        $cache = new FilesystemAdapter('orm_' . $this->name, 0, $this->rootPath . '/storage/cache/doctrine');
        $configuration = ORMSetup::createAttributeMetadataConfig($this->mappingPaths, false, $this->name, $cache);
        $configuration->enableNativeLazyObjects(true);

        return $this->entityManager = new EntityManager($this->connection(), $configuration);
    }

    public function hasOrm(): bool
    {
        return $this->definition['orm'];
    }

    /** @param array<string|int, mixed> $params
     *  @param array<string|int, mixed> $types
     *  @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = [], array $types = []): array
    {
        return $this->connection()->fetchAllAssociative($sql, $params, $types);
    }

    /** @param array<string|int, mixed> $params
     *  @param array<string|int, mixed> $types
     *  @return array<string, mixed>|null
     */
    public function queryOne(string $sql, array $params = [], array $types = []): ?array
    {
        $row = $this->connection()->fetchAssociative($sql, $params, $types);

        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params
     *  @param array<string|int, mixed> $types
     */
    public function fetchValue(string $sql, array $params = [], array $types = []): mixed
    {
        $value = $this->connection()->fetchOne($sql, $params, $types);

        return $value === false ? null : $value;
    }

    /** @param array<string|int, mixed> $params
     *  @param array<string|int, mixed> $types
     */
    public function execute(string $sql, array $params = [], array $types = []): int
    {
        $affected = $this->connection()->executeStatement($sql, $params, $types);
        if (is_string($affected)) {
            if (!ctype_digit($affected) || strlen($affected) > strlen((string) PHP_INT_MAX) || strlen($affected) === strlen((string) PHP_INT_MAX) && strcmp($affected, (string) PHP_INT_MAX) > 0) {
                throw new \OverflowException('Affected row count exceeds PHP integer range.');
            }

            return (int) $affected;
        }

        return $affected;
    }

    /** @param array<string|int, mixed> $params
     *  @param array<string|int, mixed> $types
     *  @return iterable<array<string, mixed>>
     */
    public function iterate(string $sql, array $params = [], array $types = []): iterable
    {
        return $this->connection()->iterateAssociative($sql, $params, $types);
    }

    /** @template T
     *  @param callable(self): T $operation
     *  @return T
     */
    public function transaction(callable $operation): mixed
    {
        return $this->connection()->transactional(fn (): mixed => $operation($this));
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        $pending = $this->migrations()->getMigrationStatusCalculator()->getNewMigrations();

        return array_map(static fn ($migration): string => (string) $migration->getVersion(), $pending->getItems());
    }

    public function migrate(): int
    {
        $factory = $this->migrations();
        $pending = $factory->getMigrationStatusCalculator()->getNewMigrations();
        if (count($pending) === 0) {
            return 0;
        }
        $factory->getMetadataStorage()->ensureInitialized();
        $versions = array_map(static fn ($migration) => $migration->getVersion(), $pending->getItems());
        $plan = $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP);
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());

        return count($plan);
    }

    public function resetRequest(): void
    {
        if ($this->entityManager !== null && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }
    }

    private function migrations(): DependencyFactory
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }
        $configuration = new Configuration();
        foreach ($this->migrationDirectories as $namespace => $path) {
            $configuration->addMigrationsDirectory($namespace, $path);
        }
        $storage = new \Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration();
        $storage->setTableName($this->definition['migrations_table']);
        $configuration->setMetadataStorageConfiguration($storage);
        $configuration->setAllOrNothing(false);

        return $this->migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->connection()));
    }
}
