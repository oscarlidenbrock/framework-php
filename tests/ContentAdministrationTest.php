<?php

declare(strict_types=1);

use Framework\Core;
use Framework\Security\AccessDeniedException;
use Module\Content\Administration\ContentAdministration;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentStorage;
use Module\User\AccountManager;
use Module\User\RoleManager;
use Module\User\SecurityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ContentAdministrationTest extends TestCase
{
    private string $root;
    private Core $core;
    private ContentStorage $storage;
    private ContentAdministration $administration;
    private AccountManager $accounts;
    private SecurityManager $security;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-admin-content-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/testing', 'modules/core', 'themes/core'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        foreach (['user', 'admin', 'content'] as $module) {
            symlink(dirname(__DIR__) . '/modules/core/' . $module, $this->root . '/modules/core/' . $module);
        }
        foreach (['default', 'admin'] as $theme) {
            symlink(dirname(__DIR__) . '/themes/core/' . $theme, $this->root . '/themes/core/' . $theme);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [user, admin, content]\nactive_theme: default\nadmin_theme: admin_theme\n");
        file_put_contents($this->root . '/config/users.yaml', "users:\n  connection: default\n  registration_enabled: true\n");
        file_put_contents($this->root . '/config/content.yaml', "content:\n  connection: default\n  display_modes:\n    full: null\n    summary: [title]\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $this->core->database()->migrate();
        $container = $this->core->container();
        $types = $container->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        $types->create('article', 'Article');
        $types->addField('article', ['name' => 'body', 'type' => 'text']);
        $types->schema('article')->apply();
        $this->storage = $container->get('content.storage');
        $this->administration = $container->get('content.administration');
        $this->accounts = $container->get('user.accounts');
        $this->security = $container->get('user.security');
        $this->accounts->createFirstAdministrator('admin@example.com', 'long secure password');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAdministratorCrudAndPublishedView(): void
    {
        self::assertSame(403, $this->request('/admin/content')->getStatusCode());
        self::assertSame(403, $this->request('/admin/content/article/new', 'POST', ['title' => 'Denied'])->getStatusCode());
        [$cookie] = $this->login('admin@example.com', 'long secure password');
        self::assertSame(0, (int) $this->core->database()->fetchValue('SELECT COUNT(*) FROM role_permissions'));
        $dashboard = $this->request('/admin', cookies: $cookie);
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Quick access', $dashboard->getContent());
        self::assertStringContainsString('href="/admin/content"', $dashboard->getContent());
        self::assertStringContainsString('/assets/themes/admin_theme/css/admin.css', $dashboard->getContent());
        $index = $this->request('/admin/content', cookies: $cookie);
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('Main navigation', $index->getContent());
        self::assertStringContainsString('href="/admin/content"', $index->getContent());
        self::assertStringContainsString('<title>Administration</title>', $index->getContent());
        $new = $this->request('/admin/content/article/new', cookies: $cookie);
        self::assertSame(200, $new->getStatusCode());
        self::assertStringContainsString('name="title"', $new->getContent());
        self::assertStringNotContainsString('name="owner"', $new->getContent());
        $csrf = $this->csrf($new);
        self::assertSame(403, $this->request('/admin/content/article/new', 'POST', ['title' => 'Missing token'], $cookie)->getStatusCode());
        $invalid = $this->request('/admin/content/article/new', 'POST', ['_csrf' => $csrf, 'title' => ' ', 'body' => 'Keep this'], $cookie);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Keep this', $invalid->getContent());
        self::assertStringContainsString('field-error', $invalid->getContent());
        $created = $this->request('/admin/content/article/new', 'POST', ['_csrf' => $csrf, 'title' => 'First', 'body' => '<script>safe</script>', 'owner' => '99'], $cookie);
        self::assertSame(422, $created->getStatusCode());
        $created = $this->request('/admin/content/article/new', 'POST', ['_csrf' => $csrf, 'title' => 'First', 'body' => '<script>safe</script>'], $cookie);
        self::assertSame(302, $created->getStatusCode());
        $record = $this->storage->findBy('article', [])[0];
        self::assertSame(1, $record->get('owner'));
        self::assertFalse($record->get('published'));
        self::assertSame(404, $this->request('/content/article/' . $record->id())->getStatusCode());
        $list = $this->request('/admin/content/article?notice=created', cookies: $cookie);
        self::assertStringContainsString('Content created.', $list->getContent());
        self::assertStringContainsString('Draft', $list->getContent());
        self::assertStringContainsString('href="/admin/content/article/' . $record->id() . '/edit"', $list->getContent());
        $edit = $this->request('/admin/content/article/' . $record->id() . '/edit', cookies: $cookie);
        $updated = $this->request('/admin/content/article/' . $record->id() . '/edit', 'POST', ['_csrf' => $this->csrf($edit), 'title' => 'Published article', 'published' => '1'], $cookie);
        self::assertSame(302, $updated->getStatusCode());
        $public = $this->request('/content/article/' . $record->id());
        self::assertSame(200, $public->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;safe&lt;/script&gt;', $public->getContent());
        $confirm = $this->request('/admin/content/article/' . $record->id() . '/delete', cookies: $cookie);
        self::assertStringContainsString('Confirm deletion', $confirm->getContent());
        self::assertSame(403, $this->request('/admin/content/article/' . $record->id() . '/delete', 'POST', [], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/content/article/' . $record->id() . '/delete', 'POST', ['_csrf' => $this->csrf($confirm)], $cookie)->getStatusCode());
        self::assertSame(404, $this->request('/content/article/' . $record->id())->getStatusCode());
    }

    public function testOwnContentAndDirectServiceChecks(): void
    {
        $guest = $this->accounts->register('guest@example.com', 'long secure password');
        [$adminCookie] = $this->login('admin@example.com', 'long secure password');
        $roles = $this->core->container()->get('user.roles');
        self::assertInstanceOf(RoleManager::class, $roles);
        $this->security->begin(Request::create('http://localhost/admin', cookies: $adminCookie));
        try {
            $roles->create('author');
            foreach (['content.create', 'content.edit_own', 'content.delete_own'] as $permission) {
                $roles->grant('author', $permission);
            }
            $roles->assign($guest->id(), 'author');
        } finally {
            $this->security->end();
        }
        $other = $this->storage->create('article', ['title' => 'Other']);
        $this->storage->save($other);
        [$guestCookie] = $this->login('guest@example.com', 'long secure password');
        self::assertSame(403, $this->request('/admin/content/article', cookies: $guestCookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/content/article/' . $other->id() . '/edit', cookies: $guestCookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/content/article/' . $other->id() . '/delete', cookies: $guestCookie)->getStatusCode());
        $new = $this->request('/admin/content/article/new', cookies: $guestCookie);
        self::assertStringNotContainsString('name="published"', $new->getContent());
        $token = $this->csrf($new);
        self::assertSame(403, $this->request('/admin/content/article/new', 'POST', ['_csrf' => $token, 'title' => 'Bypass', 'published' => '1'], $guestCookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/content/article/new', 'POST', ['_csrf' => $token, 'title' => 'Mine'], $guestCookie)->getStatusCode());
        $mine = $this->storage->findBy('article', ['title' => 'Mine'])[0];
        self::assertSame($guest->id(), $mine->get('owner'));
        $edit = $this->request('/admin/content/article/' . $mine->id() . '/edit', cookies: $guestCookie);
        self::assertSame(200, $edit->getStatusCode());
        self::assertSame(302, $this->request('/admin/content/article/' . $mine->id() . '/edit', 'POST', ['_csrf' => $this->csrf($edit), 'title' => 'Mine updated'], $guestCookie)->getStatusCode());
        $this->security->begin(Request::create('http://localhost/admin', cookies: $guestCookie));
        try {
            $mine = $this->storage->find('article', $mine->id());
            $mine->set('published', true);
            try {
                $this->storage->save($mine);
                self::fail('Expected publication denial.');
            } catch (AccessDeniedException $exception) {
                self::assertSame('Access denied.', $exception->getMessage());
            }
            $other->set('title', 'Tampered');
            try {
                $this->administration->save($other);
                self::fail('Expected edit denial.');
            } catch (AccessDeniedException $exception) {
                self::assertSame('Access denied.', $exception->getMessage());
            }
            try {
                $this->storage->delete($other);
                self::fail('Expected delete denial.');
            } catch (AccessDeniedException $exception) {
                self::assertSame('Access denied.', $exception->getMessage());
            }
        } finally {
            $this->security->end();
        }
        $confirmation = $this->request('/admin/content/article/' . $mine->id() . '/delete', cookies: $guestCookie);
        self::assertSame(302, $this->request('/admin/content/article/' . $mine->id() . '/delete', 'POST', ['_csrf' => $this->csrf($confirmation)], $guestCookie)->getStatusCode());
    }

    /** @return array{array<string, string>, string} */
    private function login(string $email, string $password): array
    {
        $page = $this->request('/login');
        $cookie = $this->cookie($page);
        $response = $this->request('/login', 'POST', ['_csrf' => $this->csrf($page), 'email' => $email, 'password' => $password], $cookie);
        self::assertSame(302, $response->getStatusCode());
        return [$this->cookie($response), $this->csrf($page)];
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
