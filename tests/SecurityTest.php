<?php

declare(strict_types=1);

use Framework\Core;
use Module\User\AccountManager;
use Module\User\SecurityManager;
use Module\User\RoleManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityTest extends TestCase
{
    private string $root;
    private Core $core;
    private AccountManager $accounts;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-security-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/testing', 'modules/core', 'themes/core'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        foreach (['user', 'admin'] as $module) {
            symlink(dirname(__DIR__) . '/modules/core/' . $module, $this->root . '/modules/core/' . $module);
        }
        foreach (['default', 'admin'] as $theme) {
            symlink(dirname(__DIR__) . '/themes/core/' . $theme, $this->root . '/themes/core/' . $theme);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [user, admin]\nactive_theme: default\nadmin_theme: admin_theme\n");
        file_put_contents($this->root . '/config/users.yaml', "users:\n  connection: default\n  registration_enabled: false\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $this->core->database()->migrate();
        $this->accounts = $this->core->container()->get('user.accounts');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAnonymousLoginAdministrationAndLogout(): void
    {
        $this->accounts->createFirstAdministrator('Admin@Example.com', 'long secure password');
        self::assertSame(403, $this->request('/admin')->getStatusCode());
        self::assertSame(403, $this->request('/logout', 'POST')->getStatusCode());
        $login = $this->request('/login');
        self::assertSame(200, $login->getStatusCode());
        $cookie = $this->cookie($login);
        $csrf = $this->csrf($login);
        self::assertTrue($login->headers->getCookies()[0]->isHttpOnly());
        self::assertSame('lax', strtolower($login->headers->getCookies()[0]->getSameSite()));
        self::assertSame(403, $this->request('/login', 'POST', ['email' => 'admin@example.com', 'password' => 'long secure password'], $cookie)->getStatusCode());
        $invalid = $this->request('/login', 'POST', ['_csrf' => $csrf, 'email' => 'missing@example.com', 'password' => 'bad'], $cookie);
        self::assertSame(401, $invalid->getStatusCode());
        self::assertStringContainsString('Invalid credentials.', $invalid->getContent());
        $wrong = $this->request('/login', 'POST', ['_csrf' => $csrf, 'email' => 'admin@example.com', 'password' => 'bad'], $cookie);
        self::assertSame($invalid->getContent(), $wrong->getContent());
        $valid = $this->request('/login', 'POST', ['_csrf' => $csrf, 'email' => 'admin@example.com', 'password' => 'long secure password'], $cookie);
        self::assertSame(302, $valid->getStatusCode());
        self::assertSame('/admin', $valid->headers->get('Location'));
        $renewed = $this->cookie($valid);
        self::assertNotSame($cookie, $renewed);
        self::assertSame(403, $this->request('/logout', 'POST', ['_csrf' => $csrf], $renewed)->getStatusCode());
        self::assertSame(403, $this->request('/admin', cookies: $cookie)->getStatusCode());
        $dashboard = $this->request('/admin', cookies: $renewed);
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<title>Administration</title>', $dashboard->getContent());
        self::assertStringContainsString('/assets/themes/admin_theme/css/admin.css', $dashboard->getContent());
        self::assertStringContainsString('admin@example.com', $dashboard->getContent());
        self::assertSame(405, $this->request('/logout', 'GET', cookies: $renewed)->getStatusCode());
        self::assertSame(403, $this->request('/logout', 'POST', cookies: $renewed)->getStatusCode());
        $logout = $this->request('/logout', 'POST', ['_csrf' => $this->csrf($dashboard)], $renewed);
        self::assertSame(302, $logout->getStatusCode());
        self::assertSame(403, $this->request('/admin', cookies: $renewed)->getStatusCode());
        self::assertSame(403, $this->request('/admin', cookies: $this->cookie($logout))->getStatusCode());
    }

    public function testRegistrationPolicyAndPermissionAtOperation(): void
    {
        self::assertSame(404, $this->request('/register')->getStatusCode());
        $this->expectException(LogicException::class);
        $this->accounts->register('guest@example.com', 'long secure password');
    }

    public function testRegistrationEnabledAndNoAdminGrant(): void
    {
        file_put_contents($this->root . '/config/workspaces/testing/users.yaml', "users:\n  registration_enabled: true\n");
        $core = new Core($this->root, workspace: 'testing');
        $core->boot();
        $core->database()->migrate();
        $get = $core->handle(Request::create('http://localhost/register'));
        self::assertSame(200, $get->getStatusCode());
        $cookie = $this->cookie($get);
        self::assertSame(403, $core->handle(Request::create('http://localhost/register', 'POST', ['email' => 'guest@example.com', 'password' => 'long secure password'], $cookie))->getStatusCode());
        $register = $core->handle(Request::create('http://localhost/register', 'POST', ['_csrf' => $this->csrf($get), 'email' => 'guest@example.com', 'password' => 'long secure password'], $cookie));
        self::assertSame(302, $register->getStatusCode());
        $duplicate = $core->handle(Request::create('http://localhost/register', 'POST', ['_csrf' => $this->csrf($get), 'email' => 'GUEST@example.com', 'password' => 'long secure password'], $cookie));
        self::assertSame(400, $duplicate->getStatusCode());
        $credentials = $core->database()->queryOne('SELECT password_hash FROM users WHERE email = :email', ['email' => 'guest@example.com']);
        self::assertNotSame('long secure password', $credentials['password_hash']);
        self::assertTrue(password_verify('long secure password', $credentials['password_hash']));
        self::assertSame(403, $core->handle(Request::create('http://localhost/admin', cookies: $cookie))->getStatusCode());
        $guestLogin = $core->handle(Request::create('http://localhost/login', 'POST', ['_csrf' => $this->csrf($get), 'email' => 'guest@example.com', 'password' => 'long secure password'], $cookie));
        self::assertSame('/login', $guestLogin->headers->get('Location'));
        $guestCookie = $this->cookie($guestLogin);
        self::assertStringContainsString('You are signed in.', $core->handle(Request::create('http://localhost/login', cookies: $guestCookie))->getContent());
        self::assertSame(403, $core->handle(Request::create('http://localhost/admin', cookies: $guestCookie))->getStatusCode());
        $security = $core->container()->get('user.security');
        self::assertInstanceOf(SecurityManager::class, $security);
        $security->begin(Request::create('http://localhost/login'));
        try {
            $this->expectException(Framework\Security\AccessDeniedException::class);
            $security->requirePermission('admin.access');
        } finally {
            $security->end();
        }
    }

    public function testLoginRateLimitAndGenericFailure(): void
    {
        $this->accounts->createFirstAdministrator('admin@example.com', 'long secure password');
        $login = $this->request('/login');
        $cookie = $this->cookie($login);
        $token = $this->csrf($login);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $response = $this->request('/login', 'POST', ['_csrf' => $token, 'email' => 'admin@example.com', 'password' => 'wrong'], $cookie);
            self::assertSame(401, $response->getStatusCode());
        }
        $blocked = $this->request('/login', 'POST', ['_csrf' => $token, 'email' => 'admin@example.com', 'password' => 'long secure password'], $cookie);
        self::assertSame(401, $blocked->getStatusCode());
        self::assertStringContainsString('Invalid credentials.', $blocked->getContent());
    }

    public function testRoleOperationsRequirePermission(): void
    {
        $this->accounts->createFirstAdministrator('admin@example.com', 'long secure password');
        $roles = $this->core->container()->get('user.roles');
        self::assertInstanceOf(RoleManager::class, $roles);
        try {
            $roles->create('editor');
            self::fail('Expected an authorization failure.');
        } catch (Framework\Security\AccessDeniedException $exception) {
            self::assertSame('Access denied.', $exception->getMessage());
        }
        $login = $this->request('/login');
        $authenticated = $this->request('/login', 'POST', ['_csrf' => $this->csrf($login), 'email' => 'admin@example.com', 'password' => 'long secure password'], $this->cookie($login));
        $security = $this->core->container()->get('user.security');
        $security->begin(Request::create('http://localhost/admin', cookies: $this->cookie($authenticated)));
        try {
            $roles->create('editor');
            $roles->grant('editor', 'admin.access');
            self::assertSame(0, (int) $this->core->database()->fetchValue('SELECT COUNT(*) FROM user_roles WHERE role_id = (SELECT id FROM roles WHERE name = :name)', ['name' => 'editor']));
            $this->core->database()->execute('INSERT INTO users (email, password_hash, active, created_at, updated_at) VALUES (:email, :hash, 1, :created, :updated)', [
                'email' => 'editor@example.com', 'hash' => password_hash('long secure password', PASSWORD_DEFAULT), 'created' => '2026-10-29 00:00:00', 'updated' => '2026-10-29 00:00:00',
            ]);
            $editorId = (int) $this->core->database()->connection()->lastInsertId();
            $roles->assign($editorId, 'editor');
            self::assertTrue($this->accounts->hasPermission($editorId, 'admin.access'));
        } finally {
            $security->end();
        }
    }

    public function testInvalidRouteAccessFailsBoot(): void
    {
        mkdir($this->root . '/modules/custom/invalid/config', 0775, true);
        file_put_contents($this->root . '/modules/custom/invalid/module.yaml', "id: invalid\nname: Invalid\nnamespace: Invalid\nversion: 0.1.0\nrequires: []\n");
        file_put_contents($this->root . '/modules/custom/invalid/config/routes.yaml', "invalid.index:\n  path: /invalid\n  methods: [GET]\n  controller: invalid.controller\n  access: permission:missing.access\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [user, admin, invalid]\nactive_theme: default\nadmin_theme: admin_theme\n");
        $this->expectException(InvalidArgumentException::class);
        (new Core($this->root, workspace: 'testing'))->boot();
    }

    public function testAdministratorCookieIsNotSharedWithAnotherWorkspace(): void
    {
        $this->accounts->createFirstAdministrator('admin@example.com', 'long secure password');
        $login = $this->request('/login');
        $authenticated = $this->request('/login', 'POST', ['_csrf' => $this->csrf($login), 'email' => 'admin@example.com', 'password' => 'long secure password'], $this->cookie($login));
        mkdir($this->root . '/config/workspaces/other');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\nother.local: other\n");
        $other = new Core($this->root, workspace: 'other');
        $other->boot();
        $other->database()->migrate();
        $response = $other->handle(Request::create('http://other.local/admin', cookies: $this->cookie($authenticated)));
        self::assertSame(403, $response->getStatusCode());
        self::assertArrayHasKey('fw_session_other', $this->cookie($response));
    }

    public function testHttpsCookieAndRequestStateIsolation(): void
    {
        $response = $this->core->handle(Request::create('https://localhost/login'));
        self::assertTrue($response->headers->getCookies()[0]->isSecure());
        self::assertSame('fw_session_testing', $response->headers->getCookies()[0]->getName());
        $security = $this->core->container()->get('user.security');
        self::assertNull($security->identity());
        self::assertSame(404, $this->core->handle(Request::create('https://localhost/missing'))->getStatusCode());
        self::assertNull($security->identity());
    }

    public function testCliCreatesFirstAdministrator(): void
    {
        mkdir($this->root . '/bin');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      path: " . $this->root . "/users.sqlite\n      orm: false\n");
        [$migrateCode, $migrateOutput] = $this->console(['database:migrate', '--yes']);
        self::assertSame(0, $migrateCode, $migrateOutput);
        [$shortCode, $shortOutput] = $this->console(['user:create-admin', 'first@example.com'], ['APP_ADMIN_PASSWORD' => 'too-short']);
        self::assertSame(1, $shortCode);
        self::assertStringContainsString('at least 12 characters', $shortOutput);
        [$code, $output] = $this->console(['user:create-admin', 'first@example.com'], ['APP_ADMIN_PASSWORD' => 'long secure password']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('first@example.com', $output);
        [$againCode] = $this->console(['user:create-admin', 'second@example.com'], ['APP_ADMIN_PASSWORD' => 'long secure password']);
        self::assertSame(1, $againCode);
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
        return $matches[1] ?? throw new LogicException('CSRF token missing from response.');
    }

    /** @param list<string> $arguments
     *  @param array<string, string> $environment
     *  @return array{int, string}
     */
    private function console(array $arguments, array $environment = []): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/bin/console', '--workspace=testing', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, array_merge($_ENV, $environment));
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
