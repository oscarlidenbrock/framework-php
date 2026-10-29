<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\SchemaTool;
use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Database\DatabaseRegistry;
use Framework\Http\HttpKernel;
use Framework\Module\Module;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class DatabaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-database-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0775, true);
        $this->databaseYaml("database:\n  default: primary\n  connections:\n    primary:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: true\n    legacy:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: true\n    analytics:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: false\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDefaultNamedAndLazyIndependentConnections(): void
    {
        $registry = $this->registry();
        self::assertSame($registry->manager(), $registry->manager('primary'));
        self::assertSame($registry->manager('legacy'), $registry->manager('legacy'));
        self::assertNotSame($registry->manager(), $registry->manager('legacy'));
        $primary = $registry->manager()->connection();
        $legacy = $registry->manager('legacy')->connection();
        self::assertSame($primary, $registry->manager()->connection());
        self::assertNotSame($primary, $legacy);
        self::assertFalse($primary->isConnected());
        self::assertFalse($legacy->isConnected());

        $registry->manager()->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $registry->manager()->execute('INSERT INTO items (id, name) VALUES (:id, :name)', ['id' => 1, 'name' => 'primary']);
        $registry->manager('legacy')->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        self::assertSame('primary', $registry->manager()->fetchValue('SELECT name FROM items WHERE id = ?', [1]));
        self::assertSame([], $registry->manager('legacy')->query('SELECT * FROM items'));
    }

    public function testDifferentDriversCanBeRegisteredWithoutConnecting(): void
    {
        $this->databaseYaml("database:\n  default: primary\n  connections:\n    primary:\n      params: {driver: pdo_sqlite, memory: true}\n    legacy:\n      params: {driver: pdo_mysql, host: localhost, dbname: legacy}\n    reporting:\n      params: {driver: pdo_pgsql, host: localhost, dbname: reporting}\n");
        $registry = $this->registry();
        self::assertSame('pdo_sqlite', $registry->manager()->connection()->getParams()['driver']);
        self::assertSame('pdo_mysql', $registry->manager('legacy')->connection()->getParams()['driver']);
        self::assertSame('pdo_pgsql', $registry->manager('reporting')->connection()->getParams()['driver']);
        self::assertFalse($registry->manager('legacy')->connection()->isConnected());
        self::assertFalse($registry->manager('reporting')->connection()->isConnected());
    }

    public function testQueriesParametersResultsAndIteration(): void
    {
        $db = $this->registry()->manager('analytics');
        $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, score INTEGER)');
        self::assertSame(1, $db->execute('INSERT INTO items (id, name, score) VALUES (?, ?, ?)', [1, 'alpha', 5]));
        self::assertSame(1, $db->execute('INSERT INTO items (id, name, score) VALUES (?, ?, ?)', [2, 'beta', null]));
        self::assertSame([['id' => 1, 'name' => 'alpha']], $db->query('SELECT id, name FROM items WHERE id IN (?)', [[1]], [ArrayParameterType::INTEGER]));
        self::assertSame(['name' => 'beta'], $db->queryOne('SELECT name FROM items WHERE id = :id', ['id' => 2], ['id' => ParameterType::INTEGER]));
        self::assertNull($db->queryOne('SELECT name FROM items WHERE id = 999'));
        self::assertNull($db->fetchValue('SELECT name FROM items WHERE id = 999'));
        self::assertNull($db->fetchValue('SELECT score FROM items WHERE id = 2'));
        self::assertSame(1, $db->execute('UPDATE items SET name = :name WHERE id = :id', ['name' => 'updated', 'id' => 1]));
        self::assertSame(1, $db->execute('DELETE FROM items WHERE id = ?', [2]));
        self::assertSame([['id' => 1, 'name' => 'updated']], iterator_to_array($db->iterate('SELECT id, name FROM items')));
        self::assertSame([], $db->query('SELECT id FROM items WHERE name = ?', ["updated' OR 1=1 --"]));
    }

    public function testTransactionRollbackDoesNotAffectAnotherConnection(): void
    {
        $registry = $this->registry();
        $primary = $registry->manager();
        $legacy = $registry->manager('legacy');
        $primary->execute('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $legacy->execute('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $legacy->execute('INSERT INTO items (id) VALUES (1)');
        try {
            $primary->transaction(static function ($db): void {
                $db->execute('INSERT INTO items (id) VALUES (2)');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame([], $primary->query('SELECT id FROM items'));
        self::assertSame([['id' => 1]], $legacy->query('SELECT id FROM items'));
    }

    public function testDisabledOrmAndUnknownConnectionFailClearly(): void
    {
        $registry = $this->registry();
        try {
            $registry->manager('analytics')->entityManager();
            self::fail('Expected disabled ORM error.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('ORM is disabled', $exception->getMessage());
        }
        $this->expectExceptionMessage('Database connection not found: missing');
        $registry->manager('missing');
    }

    public function testOptionalConfigurationAndInvalidDefinitions(): void
    {
        unlink($this->root . '/config/database.yaml');
        $registry = $this->registry();
        self::assertSame([], $registry->all());
        $this->expectExceptionMessage('No database connections configured');
        $registry->manager();
    }

    public function testMissingEnvironmentVariableIsResolvedOnlyOnAccess(): void
    {
        $this->databaseYaml("database:\n  default: primary\n  connections:\n    primary:\n      url_env: FRAMEWORK_TEST_MISSING_DATABASE_URL\n      orm: false\n");
        $registry = $this->registry();
        self::assertSame('primary', $registry->manager()->name);
        $this->expectExceptionMessage('Missing database URL environment variable');
        $registry->manager()->connection();
    }

    public function testEnvironmentUrlProvidesSqliteConnectionWithoutOpeningAtBoot(): void
    {
        $name = 'FRAMEWORK_TEST_DATABASE_URL_' . strtoupper(bin2hex(random_bytes(4)));
        putenv($name . '=sqlite:///:memory:');
        try {
            $this->databaseYaml("database:\n  default: primary\n  connections:\n    primary:\n      url_env: {$name}\n      orm: false\n");
            $registry = $this->registry();
            $connection = $registry->manager()->connection();
            self::assertFalse($connection->isConnected());
            self::assertSame(1, $registry->manager()->fetchValue('SELECT 1'));
        } finally {
            putenv($name);
        }
    }

    public function testInvalidDefaultAndConnectionNamesAreRejected(): void
    {
        $this->databaseYaml("database:\n  default: missing\n  connections:\n    primary:\n      params: {driver: pdo_sqlite, memory: true}\n");
        $this->expectExceptionMessage('Database default must name a configured connection');
        $this->registry();
    }

    public function testEntityMappingCannotUseOrmDisabledConnection(): void
    {
        $path = $this->root . '/modules/custom/fixture';
        mkdir($path . '/src/Entity', 0775, true);
        $module = new Module('fixture', 'Fixture', $path, [], ['entity_manager' => 'analytics']);
        $this->expectExceptionMessage('Unknown entity manager for module fixture');
        $this->registry([$module]);
    }

    public function testMappingCannotEscapeModuleDirectory(): void
    {
        $path = $this->root . '/modules/custom/fixture';
        mkdir($path, 0775, true);
        $module = new Module('fixture', 'Fixture', $path, [], ['mappings' => ['primary' => ['../outside']]]);
        $this->expectExceptionMessage('Invalid module database path');
        $this->registry([$module]);
    }

    public function testOrmMappingsAreIndependentAndRepositoriesWork(): void
    {
        [$module, $defaultClass, $legacyClass] = $this->entityModule();
        $registry = $this->registry([$module]);
        $primaryEm = $registry->manager()->entityManager();
        $legacyEm = $registry->manager('legacy')->entityManager();
        self::assertSame($primaryEm, $registry->manager()->entityManager());
        self::assertNotSame($primaryEm, $legacyEm);
        self::assertSame([$defaultClass], array_map(static fn ($metadata): string => $metadata->name, $primaryEm->getMetadataFactory()->getAllMetadata()));
        self::assertSame([$legacyClass], array_map(static fn ($metadata): string => $metadata->name, $legacyEm->getMetadataFactory()->getAllMetadata()));
        self::assertDirectoryExists($this->root . '/var/cache/default/doctrine');
        (new SchemaTool($primaryEm))->createSchema($primaryEm->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($legacyEm))->createSchema($legacyEm->getMetadataFactory()->getAllMetadata());
        $primaryEm->persist(new $defaultClass(1, 'primary'));
        $primaryEm->flush();
        $legacyEm->persist(new $legacyClass(1, 'legacy'));
        $legacyEm->flush();
        $primaryEntity = $primaryEm->getRepository($defaultClass)->find(1);
        self::assertSame('primary', $primaryEntity->name);
        self::assertSame('legacy', $legacyEm->getRepository($legacyClass)->find(1)->name);
        $registry->resetRequest();
        self::assertFalse($primaryEm->contains($primaryEntity));
    }

    public function testMigrationsHaveIndependentHistories(): void
    {
        $module = $this->migrationModule();
        $registry = $this->registry([$module]);
        $primary = $registry->manager();
        $legacy = $registry->manager('legacy');
        self::assertCount(1, $primary->pendingMigrations());
        self::assertCount(1, $legacy->pendingMigrations());
        self::assertSame(1, $primary->migrate());
        self::assertSame([], $primary->pendingMigrations());
        self::assertCount(1, $legacy->pendingMigrations());
        self::assertSame(1, $legacy->migrate());
        self::assertSame(0, $legacy->migrate());
        self::assertSame('primary', $primary->fetchValue('SELECT name FROM migration_probe'));
        self::assertSame('legacy', $legacy->fetchValue('SELECT name FROM migration_probe'));
    }

    public function testHttpRequestClearsOrmIdentityMap(): void
    {
        [$module, $defaultClass] = $this->entityModule();
        $registry = $this->registry([$module]);
        $em = $registry->manager()->entityManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new $defaultClass(1, 'stored'));
        $em->flush();
        $controller = new class ($em, $defaultClass) {
            public ?object $loaded = null;

            /** @param class-string $class */
            public function __construct(private Doctrine\ORM\EntityManagerInterface $em, private string $class)
            {
            }

            public function __invoke(Request $request): Response
            {
                $this->loaded = $this->em->getRepository($this->class)->find(1);
                return new Response($this->loaded->name);
            }
        };
        $container = new ContainerBuilder();
        $container->set('record.controller', $controller);
        $routes = new RouteCollection();
        $routes->add('record', new Route('/record', ['_controller' => 'record.controller', '_access' => 'public'], methods: ['GET']));
        $assets = new AssetManager(new LibraryResolver(new LibraryRegistry($this->root, [], [])), []);
        $kernel = new HttpKernel($routes, $container, $assets, $registry);
        self::assertSame('stored', $kernel->handle(Request::create('/record'))->getContent());
        self::assertFalse($em->contains($controller->loaded));
    }

    public function testHttpResponseDoesNotExposeDatabaseErrors(): void
    {
        $registry = $this->registry();
        $controller = static function (Request $request) use ($registry): Response {
            $registry->manager()->query('SELECT * FROM missing_table');
            return new Response('unreachable');
        };
        $container = new ContainerBuilder();
        $container->set('database.controller', $controller);
        $routes = new RouteCollection();
        $routes->add('database', new Route('/database', ['_controller' => 'database.controller', '_access' => 'public'], methods: ['GET']));
        $assets = new AssetManager(new LibraryResolver(new LibraryRegistry($this->root, [], [])), []);
        $response = (new HttpKernel($routes, $container, $assets, $registry))->handle(Request::create('/database'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $response->getContent());
    }

    public function testCrossConnectionOrmAssociationIsRejected(): void
    {
        [$module] = $this->entityModule(true);
        $registry = $this->registry([$module]);
        $this->expectExceptionMessage('Cross-connection ORM association');
        $registry->validateMappings();
    }

    /** @param list<Module> $modules */
    private function registry(array $modules = []): DatabaseRegistry
    {
        return new DatabaseRegistry($this->root, $this->root . '/config/database.yaml', $modules);
    }

    private function databaseYaml(string $contents): void
    {
        file_put_contents($this->root . '/config/database.yaml', $contents);
    }

    /** @return array{Module, class-string, class-string} */
    private function entityModule(bool $crossAssociation = false): array
    {
        $namespace = 'DatabaseFixture' . bin2hex(random_bytes(4));
        $path = $this->root . '/modules/custom/fixture';
        mkdir($path . '/src/Entity', 0775, true);
        mkdir($path . '/src/LegacyEntity', 0775, true);
        $defaultClass = 'Module\\' . $namespace . '\\Entity\\Record';
        $legacyClass = 'Module\\' . $namespace . '\\LegacyEntity\\Record';
        foreach (['Entity' => 'primary_records', 'LegacyEntity' => 'legacy_records'] as $folder => $table) {
            $association = $crossAssociation && $folder === 'Entity'
                ? "#[ORM\\ManyToOne(targetEntity: \\Module\\{$namespace}\\LegacyEntity\\Record::class)] public ?\\Module\\{$namespace}\\LegacyEntity\\Record \$legacy = null;"
                : '';
            file_put_contents($path . '/src/' . $folder . '/Record.php', str_replace(['%NAMESPACE%', '%FOLDER%', '%TABLE%', '%ASSOCIATION%'], [$namespace, $folder, $table, $association], <<<'PHP'
<?php
declare(strict_types=1);
namespace Module\%NAMESPACE%\%FOLDER%;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: '%TABLE%')]
class Record
{
    public function __construct(#[ORM\Id] #[ORM\Column(type: 'integer')] public int $id, #[ORM\Column(type: 'string')] public string $name) {}
    %ASSOCIATION%
}
PHP));
        }
        $loader = require dirname(__DIR__) . '/vendor/autoload.php';
        $loader->addPsr4('Module\\' . $namespace . '\\', $path . '/src');
        $module = new Module('fixture', $namespace, $path, [], ['entity_manager' => 'primary', 'mappings' => ['legacy' => ['src/LegacyEntity']]]);

        return [$module, $defaultClass, $legacyClass];
    }

    private function migrationModule(): Module
    {
        $namespace = 'MigrationFixture' . bin2hex(random_bytes(4));
        $path = $this->root . '/modules/custom/fixture';
        mkdir($path . '/migrations/legacy', 0775, true);
        foreach (['migrations' => 'primary', 'migrations/legacy' => 'legacy'] as $directory => $value) {
            $classNamespace = 'Module\\' . $namespace . ($value === 'primary' ? '\\Migrations' : '\\LegacyMigrations');
            file_put_contents($path . '/' . $directory . '/Version1.php', str_replace(['%NAMESPACE%', '%VALUE%'], [$classNamespace, $value], <<<'PHP'
<?php
declare(strict_types=1);
namespace %NAMESPACE%;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version1 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE migration_probe (name VARCHAR(30) NOT NULL)");
        $this->addSql("INSERT INTO migration_probe (name) VALUES ('%VALUE%')");
    }
}
PHP));
        }
        return new Module('fixture', $namespace, $path, [], ['entity_manager' => 'primary', 'migrations' => ['legacy' => ['Module\\' . $namespace . '\\LegacyMigrations' => 'migrations/legacy']]]);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
