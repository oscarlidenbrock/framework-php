<?php

declare(strict_types=1);

use Framework\Core;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor', 0775, true);
        mkdir($this->root . '/config');
        mkdir($this->root . '/config/workspaces/development', 0775, true);
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: development\n");
        mkdir($this->root . '/modules/custom/fixture/config', 0775, true);
        mkdir($this->root . '/modules/custom/fixture/src/Controller', 0775, true);
        mkdir($this->root . '/themes/core/default/templates/layouts', 0775, true);
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [fixture]\n");
        file_put_contents($this->root . '/themes/core/default/theme.yaml', "id: default\nname: Default\nnamespace: FixtureDefaultTheme\nversion: 0.1.0\nparent: null\n");
        file_put_contents($this->root . '/themes/core/default/templates/layouts/base.html.twig', '<html>{% block content %}{% endblock %}</html>');
        file_put_contents($this->root . '/modules/custom/fixture/module.yaml', "id: fixture\nname: Fixture\nnamespace: FixtureGreeting\nversion: 0.1.0\nrequires: []\n");
        file_put_contents($this->root . '/modules/custom/fixture/config/services.yaml', "services:\n  fixture.controller:\n    class: Module\\FixtureGreeting\\Controller\\GreetingController\n    public: true\n");
        file_put_contents($this->root . '/modules/custom/fixture/config/routes.yaml', "fixture.index:\n  path: /fixture\n  methods: [GET]\n  controller: fixture.controller\n");
        file_put_contents($this->root . '/modules/custom/fixture/src/Controller/GreetingController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Module\FixtureGreeting\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
final class GreetingController
{
    public function __invoke(Request $request): Response
    {
        return new Response('Fixture works');
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testIndependentModuleProvidesRouteAndService(): void
    {
        $core = new Core($this->root, workspace: 'development');
        self::assertSame('Fixture works', $core->handle(Request::create('/fixture'))->getContent());
        self::assertSame(['fixture'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
    }

    public function testUnsupportedAccessRuleFailsBoot(): void
    {
        file_put_contents($this->root . '/modules/custom/fixture/config/routes.yaml', "fixture.index:\n  path: /fixture\n  methods: [GET]\n  controller: fixture.controller\n  access: admin\n");
        $this->expectException(InvalidArgumentException::class);
        (new Core($this->root, workspace: 'development'))->boot();
    }

    public function testControllerMustBePublicService(): void
    {
        file_put_contents($this->root . '/modules/custom/fixture/config/services.yaml', "services:\n  fixture.controller:\n    class: Module\\FixtureGreeting\\Controller\\GreetingController\n");
        $this->expectExceptionMessage('Route controller must be a public service');
        (new Core($this->root, workspace: 'development'))->boot();
    }

    public function testNamedDatabaseServicesAndDefaultAliasesAreInjectable(): void
    {
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: primary\n  connections:\n    primary:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: true\n    legacy:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: true\n");
        file_put_contents($this->root . '/modules/custom/fixture/src/Controller/InjectedController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Module\FixtureGreeting\Controller;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
final class InjectedController
{
    public function __construct(private Connection $primary, private Connection $legacy, private EntityManagerInterface $em, private EntityManagerInterface $legacyEm) {}
    public function __invoke(Request $request): Response
    {
        return new Response($this->primary !== $this->legacy && $this->em !== $this->legacyEm && $this->em->getConnection() === $this->primary && $this->legacyEm->getConnection() === $this->legacy && !$this->primary->isConnected() ? 'injected' : 'wrong');
    }
}
PHP);
        file_put_contents($this->root . '/modules/custom/fixture/config/services.yaml', "services:\n  fixture.controller:\n    class: Module\\FixtureGreeting\\Controller\\InjectedController\n    arguments: ['@Doctrine\\DBAL\\Connection', '@database.legacy.connection', '@Doctrine\\ORM\\EntityManagerInterface', '@database.legacy.entity_manager']\n    public: true\n");
        $core = new Core($this->root, workspace: 'development');
        self::assertSame($core->database(), $core->database('primary'));
        self::assertNotSame($core->database(), $core->database('legacy'));
        self::assertSame('injected', $core->handle(Request::create('/fixture'))->getContent());
        self::assertFalse($core->database()->connection()->isConnected());
    }

    public function testDatabaseCliCommandsSelectConnectionAndRequireExplicitMigrationFlag(): void
    {
        mkdir($this->root . '/bin');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: primary\n  connections:\n    primary:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: false\n    legacy:\n      params: {driver: pdo_sqlite, memory: true}\n      orm: false\n");

        [$status, $out] = $this->console(['database:status']);
        self::assertSame(0, $status);
        self::assertStringContainsString("primary\t0 pending migrations", $out);
        [$status, $out] = $this->console(['database:status', '--connection=legacy']);
        self::assertSame(0, $status);
        self::assertStringContainsString("legacy\t0 pending migrations", $out);
        [$status, $out] = $this->console(['database:validate', '--connection=legacy']);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString("legacy\tvalid", $out);
        [$status, $out] = $this->console(['database:migrate']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Schema changes require --yes', $out);
        [$status, $out] = $this->console(['database:migrate', '--connection=legacy', '--yes']);
        self::assertSame(0, $status);
        self::assertStringContainsString("legacy\t0 migrations applied", $out);
    }

    /** @param list<string> $arguments
     *  @return array{int, string}
     */
    private function console(array $arguments): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/bin/console', '--workspace=development', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start CLI fixture.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
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
