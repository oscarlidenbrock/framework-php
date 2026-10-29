<?php

declare(strict_types=1);

use Framework\Config\InstallationSnapshot;
use Framework\Core;
use Framework\Security\AccessDeniedException;
use Module\Site\InstallationManager;
use Module\User\AccountManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InstallationSelectionTest extends TestCase
{
    private string $root;
    private Core $core;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-installation-' . bin2hex(random_bytes(8));
        foreach (['bin', 'vendor', 'config/workspaces/testing', 'config/workspaces/second', 'modules/core', 'modules/custom/addon/migrations', 'modules/custom/broken', 'themes/core/empty', 'themes/core/bad_library'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        foreach (['user', 'admin', 'site'] as $module) {
            symlink(dirname(__DIR__) . '/modules/core/' . $module, $this->root . '/modules/core/' . $module);
        }
        symlink(dirname(__DIR__) . '/modules/custom/example', $this->root . '/modules/custom/example');
        foreach (['default', 'admin'] as $theme) {
            symlink(dirname(__DIR__) . '/themes/core/' . $theme, $this->root . '/themes/core/' . $theme);
        }
        file_put_contents($this->root . '/themes/core/empty/theme.yaml', "id: empty\nname: Empty\nnamespace: Empty\nversion: 0.1.0\nlibraries: []\n");
        file_put_contents($this->root . '/themes/core/bad_library/theme.yaml', "id: bad_library\nname: Bad Library\nnamespace: BadLibrary\nversion: 0.1.0\nlibraries: [bad_library/missing]\n");
        file_put_contents($this->root . '/modules/custom/addon/module.yaml', "id: addon\nname: Addon\nnamespace: Addon\nversion: 0.1.0\nrequires: [example]\n");
        file_put_contents($this->root . '/modules/custom/broken/module.yaml', "id: broken\nname: Broken\nnamespace: Broken\nversion: 0.1.0\nrequires: [missing]\n");
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\nsecond.test: second\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [user, admin, site, example]\nactive_theme: default\nadmin_theme: admin_theme\nmodule_discovery_cache: true\ntheme_discovery_cache: true\n");
        file_put_contents($this->root . '/config/site.yaml', "site:\n  title: Test\n  email: test@example.test\n");
        file_put_contents($this->root . '/config/users.yaml', "users:\n  connection: default\n  registration_enabled: true\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      path: " . $this->root . "/installation.sqlite\n      orm: false\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testNewInstallationBootsWithoutDatabaseAndHelpWorks(): void
    {
        self::assertSame(['user', 'admin', 'site', 'example'], array_map(static fn ($module): string => $module->id, $this->core->enabledModules()));
        self::assertNull((new InstallationSnapshot($this->root, 'testing'))->read());
        self::assertSame(0, $this->command('help')[0]);
        self::assertSame(0, $this->command('module:list', workspace: 'testing')[0]);
        self::assertFalse($this->core->database()->connection()->createSchemaManager()->tablesExist(['config']));
        self::assertSame(1, $this->command('module:enable addon', workspace: 'testing')[0]);
    }

    public function testDependenciesSnapshotAndWorkspaceIsolation(): void
    {
        $this->createAddonMigration();
        $this->core->database()->migrate();
        $manager = $this->manager();
        self::assertFalse($manager->modules()['addon']['enabled']);
        try {
            $manager->disable('site');
            self::fail('Expected management module protection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('required for administration', $exception->getMessage());
        }
        try {
            $manager->enable('broken');
            self::fail('Expected missing dependency rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('requires enabled module missing', $exception->getMessage());
        }
        try {
            $manager->enable('addon');
            self::fail('Expected pending migration rejection.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('module:migrate', $exception->getMessage());
        }
        self::assertFalse($this->core->database()->connection()->createSchemaManager()->tablesExist(['addon_probe']));
        self::assertSame(0, $this->command('module:migrate addon --yes', workspace: 'testing')[0]);
        self::assertTrue($this->core->database()->connection()->createSchemaManager()->tablesExist(['addon_probe']));
        $manager->enable('addon');
        self::assertSame(['user', 'admin', 'site', 'example'], array_map(static fn ($module): string => $module->id, $this->core->enabledModules()));
        $next = $this->nextCore('testing');
        self::assertContains('addon', array_map(static fn ($module): string => $module->id, $next->enabledModules()));
        self::assertContains('addon', $next->config()->get('app.enabled_modules'));
        $nextManager = $next->container()->get('site.installation');
        self::assertInstanceOf(InstallationManager::class, $nextManager);
        try {
            $nextManager->disable('example');
            self::fail('Expected dependent module rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('requires enabled module example', $exception->getMessage());
        }
        $nextManager->disable('addon');
        self::assertNotContains('addon', array_map(static fn ($module): string => $module->id, $this->nextCore('testing')->enabledModules()));
        self::assertTrue($this->core->database()->connection()->createSchemaManager()->tablesExist(['addon_probe']));
        self::assertNull((new InstallationSnapshot($this->root, 'second'))->read());
        self::assertNotContains('addon', array_map(static fn ($module): string => $module->id, $this->nextCore('second')->enabledModules()));
        $secondManager = $this->nextCore('second')->container()->get('site.installation');
        self::assertInstanceOf(InstallationManager::class, $secondManager);
        $secondManager->selectTheme('public', 'admin_theme');
        self::assertSame('admin_theme', $this->nextCore('second')->themes()->active()->id);
        self::assertSame('default', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(3, count($this->core->database()->query("SELECT setting_key FROM config WHERE workspace = 'testing' AND setting_key LIKE 'installation.%'")));
        self::assertSame(3, count($this->core->database()->query("SELECT setting_key FROM config WHERE workspace = 'second' AND setting_key LIKE 'installation.%'")));
    }

    public function testThemeSelectionRefreshAndCliRecovery(): void
    {
        $this->core->database()->migrate();
        $manager = $this->manager();
        $manager->selectTheme('public', 'admin_theme');
        $manager->selectTheme('admin', 'default');
        self::assertSame('admin_theme', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame('admin_theme', $this->nextCore('testing')->config()->get('app.active_theme'));
        self::assertSame('default', $this->nextCore('testing')->themes()->administration()->id);
        self::assertSame('default', $this->nextCore('second')->themes()->active()->id);
        try {
            $manager->selectTheme('admin', 'empty');
            self::fail('Expected missing administration layout rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('base layout', $exception->getMessage());
        }
        try {
            $manager->selectTheme('public', 'bad_library');
            self::fail('Expected missing library rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Library not found', $exception->getMessage());
        }
        $this->expectExceptionMessage('Active theme not found');
        $manager->selectTheme('public', 'unknown');
    }

    public function testCliRecoveryAndRefreshRebuildSnapshot(): void
    {
        $this->core->database()->migrate();
        $this->manager()->selectTheme('public', 'admin_theme');
        self::assertSame(0, $this->command('installation:recover', workspace: 'testing')[0]);
        self::assertSame('default', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(0, $this->command('installation:refresh', workspace: 'testing')[0]);
        self::assertSame('admin_theme', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(0, $this->command('theme:select public default', workspace: 'testing')[0]);
        self::assertSame('default', $this->nextCore('testing')->themes()->active()->id);
        $this->core->database()->execute("UPDATE config SET value = 'missing' WHERE workspace = 'testing' AND setting_key = 'installation.active_theme'");
        self::assertSame(1, $this->command('installation:refresh', workspace: 'testing')[0]);
        self::assertSame('default', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(0, $this->command('installation:recover', workspace: 'testing')[0]);
        self::assertSame(1, $this->command('installation:reset', workspace: 'testing')[0]);
        self::assertSame(0, $this->command('installation:reset --yes', workspace: 'testing')[0]);
        self::assertSame('default', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(0, $this->command('theme:select admin default', workspace: 'testing')[0]);
    }

    public function testSnapshotBootstrapDoesNotOpenUnavailableDatabase(): void
    {
        $this->core->database()->migrate();
        $this->manager()->selectTheme('public', 'admin_theme');
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      path: " . $this->root . "/missing/installation.sqlite\n      orm: false\n");
        $next = $this->nextCore('testing');
        self::assertSame('admin_theme', $next->themes()->active()->id);
        self::assertSame('admin_theme', $next->config()->get('app.active_theme'));
    }

    public function testHttpPermissionsCsrfAndSelectionOnLaterRequest(): void
    {
        $this->core->database()->migrate();
        $accounts = $this->core->container()->get('user.accounts');
        self::assertInstanceOf(AccountManager::class, $accounts);
        $accounts->createFirstAdministrator('admin@example.com', 'long secure password');
        self::assertSame(403, $this->request('/admin/modules')->getStatusCode());
        self::assertSame(403, $this->request('/admin/themes')->getStatusCode());
        $cookie = $this->login();
        $page = $this->request('/admin/modules', cookies: $cookie);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('addon', $page->getContent());
        self::assertStringContainsString('example', $page->getContent());
        self::assertSame(403, $this->request('/admin/modules', 'POST', ['action' => 'enable', 'id' => 'addon'], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/modules', 'POST', ['_csrf' => $this->csrf($page), 'action' => 'enable', 'id' => 'addon'], $cookie)->getStatusCode());
        $next = $this->nextCore('testing');
        self::assertContains('addon', array_map(static fn ($module): string => $module->id, $next->enabledModules()));
        $themes = $this->request('/admin/themes', cookies: $cookie);
        self::assertSame(200, $themes->getStatusCode());
        self::assertSame(403, $this->request('/admin/themes', 'POST', ['kind' => 'public', 'id' => 'admin_theme'], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/themes', 'POST', ['_csrf' => $this->csrf($themes), 'kind' => 'public', 'id' => 'admin_theme'], $cookie)->getStatusCode());
        self::assertSame('admin_theme', $this->nextCore('testing')->themes()->active()->id);
        self::assertSame(422, $this->request('/admin/themes', 'POST', ['_csrf' => $this->csrf($themes), 'kind' => 'admin', 'id' => 'missing'], $cookie)->getStatusCode());
    }

    public function testUnprivilegedAccountCannotChangeSelectionThroughRoutesOrService(): void
    {
        $this->core->database()->migrate();
        $accounts = $this->core->container()->get('user.accounts');
        self::assertInstanceOf(AccountManager::class, $accounts);
        $accounts->register('guest@example.com', 'long secure password');
        $cookie = $this->login('guest@example.com');
        self::assertSame(403, $this->request('/admin/modules', cookies: $cookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/modules', 'POST', ['action' => 'enable', 'id' => 'addon'], $cookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/themes', cookies: $cookie)->getStatusCode());
        $security = $this->core->container()->get('user.security');
        self::assertInstanceOf(\Module\User\SecurityManager::class, $security);
        $security->begin(Request::create('http://localhost/admin', cookies: $cookie));
        try {
            $this->expectException(AccessDeniedException::class);
            $this->manager()->enable('addon');
        } finally {
            $security->end();
        }
    }

    private function manager(): InstallationManager
    {
        $manager = $this->core->container()->get('site.installation');
        self::assertInstanceOf(InstallationManager::class, $manager);
        return $manager;
    }

    private function createAddonMigration(): void
    {
        file_put_contents($this->root . '/modules/custom/addon/migrations/Version20261029020000.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Module\Addon\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261029020000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('addon_probe');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
    }
}
PHP);
    }

    private function nextCore(string $workspace): Core
    {
        $core = new Core($this->root, workspace: $workspace);
        $core->boot();
        return $core;
    }

    /** @return array{int, string, string} */
    private function command(string $command, ?string $workspace = null): array
    {
        $arguments = [PHP_BINARY, $this->root . '/bin/console', ...explode(' ', $command)];
        if ($workspace !== null) {
            $arguments[] = '--workspace=' . $workspace;
        }
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    /** @return array<string, string> */
    private function login(string $email = 'admin@example.com'): array
    {
        $page = $this->request('/login');
        $cookie = $this->cookie($page);
        $response = $this->request('/login', 'POST', ['_csrf' => $this->csrf($page), 'email' => $email, 'password' => 'long secure password'], $cookie);
        self::assertSame(302, $response->getStatusCode());
        return $this->cookie($response);
    }

    /** @param array<string, mixed> $data
     *  @param array<string, string> $cookies
     */
    private function request(string $path, string $method = 'GET', array $data = [], array $cookies = []): Response
    {
        return $this->core->handle(Request::create('http://localhost' . $path, $method, $data, $cookies));
    }

    /** @return array<string, string> */
    private function cookie(Response $response): array
    {
        $cookie = $response->headers->getCookies()[0];
        return [$cookie->getName() => $cookie->getValue()];
    }

    private function csrf(Response $response): string
    {
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $response->getContent(), $matches);
        return $matches[1] ?? throw new LogicException('CSRF token missing.');
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
