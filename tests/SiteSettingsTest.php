<?php

declare(strict_types=1);

use Framework\Config\SiteSettingsInterface;
use Framework\Core;
use Framework\Security\AccessDeniedException;
use Module\Site\SiteSettings;
use Module\User\AccountManager;
use Module\User\SecurityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SiteSettingsTest extends TestCase
{
    private string $root;
    private Core $core;
    private SiteSettings $settings;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-site-settings-' . bin2hex(random_bytes(8));
        foreach (['bin', 'vendor', 'config/workspaces/testing', 'config/workspaces/second', 'modules/core', 'modules/custom', 'themes/core'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        foreach (['user', 'admin', 'site'] as $module) {
            symlink(dirname(__DIR__) . '/modules/core/' . $module, $this->root . '/modules/core/' . $module);
        }
        symlink(dirname(__DIR__) . '/modules/custom/example', $this->root . '/modules/custom/example');
        foreach (['default', 'admin'] as $theme) {
            symlink(dirname(__DIR__) . '/themes/core/' . $theme, $this->root . '/themes/core/' . $theme);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\nsecond.test: second\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [user, admin, site, example]\nactive_theme: default\nadmin_theme: admin_theme\n");
        file_put_contents($this->root . '/config/site.yaml', "site:\n  title: Base Site\n  email: base@example.test\n");
        file_put_contents($this->root . '/config/workspaces/second/site.yaml', "site:\n  title: Second Site\n");
        file_put_contents($this->root . '/config/users.yaml', "users:\n  connection: default\n  registration_enabled: true\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      path: " . $this->root . "/site.sqlite\n      orm: false\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $service = $this->core->container()->get('site.settings');
        self::assertInstanceOf(SiteSettings::class, $service);
        $this->settings = $service;
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDefaultsAndBootstrapBeforeMigration(): void
    {
        self::assertSame('Base Site', $this->settings->get('title'));
        self::assertSame('base@example.test', $this->settings->get('email'));
        self::assertFalse($this->settings->details()['title']['overridden']);
        self::assertFalse($this->core->database()->connection()->createSchemaManager()->tablesExist(['config']));
        try {
            $this->settings->save(['title' => 'New title']);
            self::fail('Expected explicit migration requirement.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('database:migrate', $exception->getMessage());
        }
        $process = proc_open([PHP_BINARY, $this->root . '/bin/console', 'help'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertStringContainsString('Usage:', $output);
    }

    public function testAdminEditResetAndPublicTitleOnLaterRequest(): void
    {
        $this->installAccounts();
        self::assertSame(403, $this->request('/admin/settings')->getStatusCode());
        self::assertSame(403, $this->request('/admin/settings', 'POST', ['title' => 'Denied'])->getStatusCode());
        $cookie = $this->login('admin@example.com', 'long secure password');
        $form = $this->request('/admin/settings', cookies: $cookie);
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('Base Site', $form->getContent());
        self::assertStringContainsString('General settings', $form->getContent());
        self::assertStringContainsString('href="/admin/settings"', $form->getContent());
        self::assertSame(403, $this->request('/admin/settings', 'POST', ['title' => 'New'], $cookie)->getStatusCode());
        $csrf = $this->csrf($form);
        self::assertSame(422, $this->request('/admin/settings', 'POST', ['_csrf' => $csrf, 'title' => ' ', 'email' => 'base@example.test'], $cookie)->getStatusCode());
        self::assertSame(422, $this->request('/admin/settings', 'POST', ['_csrf' => $csrf, 'title' => 'New', 'email' => 'invalid'], $cookie)->getStatusCode());
        self::assertSame('Base Site', $this->settings->get('title'));
        $saved = $this->request('/admin/settings', 'POST', ['_csrf' => $csrf, 'title' => '<New Site>', 'email' => 'contact@example.test'], $cookie);
        self::assertSame(302, $saved->getStatusCode());
        self::assertSame('<New Site>', $this->settings->get('title'));
        self::assertSame('contact@example.test', $this->settings->get('email'));
        $public = $this->request('/example');
        self::assertSame(200, $public->getStatusCode());
        self::assertStringContainsString('&lt;New Site&gt;', $public->getContent());
        self::assertStringNotContainsString('<New Site>', $public->getContent());
        $after = $this->request('/admin/settings?saved=1', cookies: $cookie);
        self::assertStringContainsString('Settings saved.', $after->getContent());
        self::assertStringContainsString('Custom value active', $after->getContent());
        $reset = $this->request('/admin/settings', 'POST', ['_csrf' => $this->csrf($after), 'title' => '<New Site>', 'email' => 'contact@example.test', 'reset_title' => '1'], $cookie);
        self::assertSame(302, $reset->getStatusCode());
        self::assertSame('Base Site', $this->settings->get('title'));
        self::assertSame('contact@example.test', $this->settings->get('email'));
        self::assertFalse($this->settings->details()['title']['overridden']);
        self::assertTrue($this->settings->details()['email']['overridden']);
        $finalForm = $this->request('/admin/settings', cookies: $cookie);
        self::assertSame(302, $this->request('/admin/settings', 'POST', ['_csrf' => $this->csrf($finalForm), 'title' => 'Base Site', 'email' => 'contact@example.test', 'reset_email' => '1'], $cookie)->getStatusCode());
        self::assertSame('base@example.test', $this->settings->get('email'));
        self::assertFalse($this->settings->details()['email']['overridden']);
    }

    public function testWorkspaceIsolationAndEmptyOverrideSemantics(): void
    {
        $this->core->database()->migrate();
        $this->settings->save(['title' => 'Testing custom']);
        $second = new Core($this->root, workspace: 'second');
        $second->boot();
        $service = $second->container()->get(SiteSettingsInterface::class);
        self::assertInstanceOf(SiteSettingsInterface::class, $service);
        self::assertSame('Second Site', $service->get('title'));
        $secondSettings = $second->container()->get('site.settings');
        self::assertInstanceOf(SiteSettings::class, $secondSettings);
        $secondSettings->save(['title' => 'Second custom']);
        self::assertSame('Testing custom', $this->settings->get('title'));
        self::assertSame('Second custom', $secondSettings->get('title'));
        $this->core->database()->execute('UPDATE config SET value = :value WHERE workspace = :workspace AND setting_key = :key', ['value' => '', 'workspace' => 'testing', 'key' => 'title']);
        self::assertSame('', $this->settings->get('title'));
        self::assertTrue($this->settings->details()['title']['overridden']);
        $this->settings->save(['title' => null]);
        self::assertSame('Base Site', $this->settings->get('title'));
        self::assertSame('Second custom', $secondSettings->get('title'));
    }

    public function testUnauthorizedAccountCannotWriteThroughService(): void
    {
        $this->installAccounts();
        $accounts = $this->core->container()->get('user.accounts');
        self::assertInstanceOf(AccountManager::class, $accounts);
        $accounts->register('guest@example.com', 'long secure password');
        $cookie = $this->login('guest@example.com', 'long secure password');
        self::assertSame(403, $this->request('/admin/settings', cookies: $cookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/settings', 'POST', ['title' => 'Denied'], $cookie)->getStatusCode());
        $security = $this->core->container()->get('user.security');
        self::assertInstanceOf(SecurityManager::class, $security);
        $security->begin(Request::create('http://localhost/admin', cookies: $cookie));
        try {
            $this->expectException(AccessDeniedException::class);
            $this->settings->save(['title' => 'Denied']);
        } finally {
            $security->end();
        }
    }

    public function testUnknownKeysAndViewOnlyPermission(): void
    {
        $this->installAccounts();
        try {
            $this->settings->save(['database.password' => 'secret']);
            self::fail('Expected unknown setting rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown site setting.', $exception->getMessage());
        }
        $accounts = $this->core->container()->get('user.accounts');
        $roles = $this->core->container()->get('user.roles');
        $security = $this->core->container()->get('user.security');
        self::assertInstanceOf(AccountManager::class, $accounts);
        self::assertInstanceOf(\Module\User\RoleManager::class, $roles);
        self::assertInstanceOf(SecurityManager::class, $security);
        $reader = $accounts->register('reader@example.com', 'long secure password');
        $adminCookie = $this->login('admin@example.com', 'long secure password');
        $security->begin(Request::create('http://localhost/admin', cookies: $adminCookie));
        try {
            $roles->create('settings_reader');
            $roles->grant('settings_reader', 'site.settings.view');
            $roles->assign($reader->id(), 'settings_reader');
        } finally {
            $security->end();
        }
        $readerCookie = $this->login('reader@example.com', 'long secure password');
        $page = $this->request('/admin/settings', cookies: $readerCookie);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(403, $this->request('/admin/settings', 'POST', ['_csrf' => $this->csrf($page), 'title' => 'Denied', 'email' => 'base@example.test'], $readerCookie)->getStatusCode());
    }

    private function installAccounts(): void
    {
        $this->core->database()->migrate();
        $accounts = $this->core->container()->get('user.accounts');
        self::assertInstanceOf(AccountManager::class, $accounts);
        $accounts->createFirstAdministrator('admin@example.com', 'long secure password');
    }

    /** @return array<string, string> */
    private function login(string $email, string $password): array
    {
        $page = $this->request('/login');
        $cookie = $this->cookie($page);
        $response = $this->request('/login', 'POST', ['_csrf' => $this->csrf($page), 'email' => $email, 'password' => $password], $cookie);
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
