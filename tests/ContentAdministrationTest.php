<?php

declare(strict_types=1);

use Framework\Core;
use Framework\Security\AccessDeniedException;
use Module\Content\Administration\ContentAdministration;
use Module\Content\Administration\ContentStructure;
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
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n    legacy:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $this->core->database()->migrate();
        $this->core->database('legacy')->migrate();
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
        self::assertSame(403, $this->request('/admin/structure/content-types', cookies: $guestCookie)->getStatusCode());
        self::assertSame(403, $this->request('/admin/structure/content-types/new', 'POST', ['namespace' => 'blocked', 'label' => 'Blocked'], $guestCookie)->getStatusCode());
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
            $types = $this->core->container()->get('content.types');
            self::assertInstanceOf(ContentTypeManager::class, $types);
            try {
                $types->addField('article', ['name' => 'blocked', 'type' => 'text']);
                self::fail('Expected structure change denial.');
            } catch (AccessDeniedException $exception) {
                self::assertSame('Access denied.', $exception->getMessage());
            }
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

    public function testStructureManagementUsesExplicitSafeSchemaApplication(): void
    {
        self::assertSame(403, $this->request('/admin/structure/content-types')->getStatusCode());
        self::assertSame(403, $this->request('/admin/structure/content-types/new', 'POST', ['label' => 'Page'])->getStatusCode());
        [$cookie] = $this->login('admin@example.com', 'long secure password');
        $index = $this->request('/admin/structure/content-types', cookies: $cookie);
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('Add content type', $index->getContent());
        self::assertStringContainsString('Content types', $this->request('/admin', cookies: $cookie)->getContent());

        $new = $this->request('/admin/structure/content-types/new', cookies: $cookie);
        $token = $this->csrf($new);
        self::assertSame(403, $this->request('/admin/structure/content-types/new', 'POST', ['namespace' => 'page', 'label' => 'Page'], $cookie)->getStatusCode());
        $invalid = $this->request('/admin/structure/content-types/new', 'POST', ['_csrf' => $token, 'namespace' => '../bad', 'label' => 'Page'], $cookie);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Invalid or reserved content namespace', $invalid->getContent());
        self::assertSame(302, $this->request('/admin/structure/content-types/new', 'POST', ['_csrf' => $token, 'namespace' => 'page', 'label' => 'Page'], $cookie)->getStatusCode());
        $types = $this->core->container()->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertSame('pending', $types->requireType('page')->schemaStatus);
        self::assertFalse($this->core->database()->connection()->createSchemaManager()->tablesExist(['content_page_data']));
        $show = $this->request('/admin/structure/content-types/page', cookies: $cookie);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('Created at', $show->getContent());
        self::assertStringContainsString('Updated at', $show->getContent());
        self::assertStringContainsString('Owner', $show->getContent());
        self::assertSame(403, $this->request('/admin/structure/content-types/page/fields/owner', cookies: $cookie)->getStatusCode());

        $fieldPage = $this->request('/admin/structure/content-types/page/fields/new', cookies: $cookie);
        $fieldToken = $this->csrf($fieldPage);
        self::assertSame(403, $this->request('/admin/structure/content-types/page/fields/new', 'POST', ['name' => 'body', 'type' => 'text'], $cookie)->getStatusCode());
        $badField = $this->request('/admin/structure/content-types/page/fields/new', 'POST', ['_csrf' => $fieldToken, 'name' => 'body', 'label' => 'Body', 'type' => 'string', 'length' => '0'], $cookie);
        self::assertSame(422, $badField->getStatusCode());
        self::assertStringContainsString('String length must be between', $badField->getContent());
        self::assertSame(422, $this->request('/admin/structure/content-types/page/fields/new', 'POST', ['_csrf' => $fieldToken, 'name' => 'title', 'label' => 'Duplicate', 'type' => 'text'], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/structure/content-types/page/fields/new', 'POST', ['_csrf' => $fieldToken, 'name' => 'body', 'label' => 'Body', 'type' => 'text'], $cookie)->getStatusCode());

        $titlePage = $this->request('/admin/structure/content-types/page/fields/title', cookies: $cookie);
        self::assertSame(200, $titlePage->getStatusCode());
        self::assertSame(422, $this->request('/admin/structure/content-types/page/fields/title', 'POST', ['_csrf' => $this->csrf($titlePage), 'label' => 'Headline', 'type' => 'text', 'length' => '50'], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/structure/content-types/page/fields/title', 'POST', ['_csrf' => $this->csrf($titlePage), 'label' => 'Headline', 'length' => '50'], $cookie)->getStatusCode());
        self::assertSame(50, $types->fields('page')['title']->settings['length']);

        $schema = $this->request('/admin/structure/content-types/page/schema', cookies: $cookie);
        self::assertSame(200, $schema->getStatusCode());
        self::assertStringContainsString('Create table', $schema->getContent());
        self::assertStringContainsString('Apply safe changes', $schema->getContent());
        self::assertSame(403, $this->request('/admin/structure/content-types/page/schema/apply', 'POST', [], $cookie)->getStatusCode());
        self::assertSame(302, $this->request('/admin/structure/content-types/page/schema/apply', 'POST', ['_csrf' => $this->csrf($schema)], $cookie)->getStatusCode());
        self::assertSame('applied', $types->requireType('page')->schemaStatus);
        self::assertTrue($this->core->database()->connection()->createSchemaManager()->tablesExist(['content_page_data']));
        $create = $this->request('/admin/content/page/new', cookies: $cookie);
        self::assertStringContainsString('name="body"', $create->getContent());
        self::assertSame(302, $this->request('/admin/content/page/new', 'POST', ['_csrf' => $this->csrf($create), 'title' => 'Home', 'body' => 'Welcome'], $cookie)->getStatusCode());
        self::assertSame('Welcome', $this->storage->findBy('page', [])[0]->get('body'));

        $titlePage = $this->request('/admin/structure/content-types/page/fields/title', cookies: $cookie);
        self::assertSame(302, $this->request('/admin/structure/content-types/page/fields/title', 'POST', ['_csrf' => $this->csrf($titlePage), 'label' => 'Headline', 'length' => '25'], $cookie)->getStatusCode());
        $schema = $this->request('/admin/structure/content-types/page/schema', cookies: $cookie);
        self::assertStringContainsString('Requires manual review', $schema->getContent());
        self::assertStringNotContainsString('<button type="submit">Apply safe changes</button>', $schema->getContent());
        self::assertSame(409, $this->request('/admin/structure/content-types/page/schema/apply', 'POST', ['_csrf' => $this->csrf($schema)], $cookie)->getStatusCode());
        self::assertSame('pending', $types->requireType('page')->schemaStatus);
        $structure = $this->core->container()->get('content.structure');
        self::assertInstanceOf(ContentStructure::class, $structure);
        $this->security->begin(Request::create('http://localhost/admin', cookies: $cookie));
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be applied from administration');
            $structure->applySafe('page', 'default');
        } finally {
            $this->security->end();
        }
    }

    public function testStructureServiceChecksPermissions(): void
    {
        $structure = $this->core->container()->get('content.structure');
        self::assertInstanceOf(ContentStructure::class, $structure);
        $this->expectException(AccessDeniedException::class);
        $structure->create('private', 'Private', '', 'default');
    }

    public function testStructureKeepsNamedConnectionsSeparate(): void
    {
        [$cookie] = $this->login('admin@example.com', 'long secure password');
        $index = $this->request('/admin/structure/content-types?connection=legacy', cookies: $cookie);
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('No content types on this connection yet', $index->getContent());
        $new = $this->request('/admin/structure/content-types/new?connection=legacy', cookies: $cookie);
        $response = $this->request('/admin/structure/content-types/new?connection=legacy', 'POST', ['_csrf' => $this->csrf($new), 'namespace' => 'remote', 'label' => 'Remote'], $cookie);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('connection=legacy', $response->headers->get('Location'));
        $types = $this->core->container()->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertSame('legacy', $types->requireType('remote', 'legacy')->connection);
        self::assertNull($types->get('remote', 'default'));
    }

    public function testHttpManagerCannotForceSchemaApplication(): void
    {
        [$cookie] = $this->login('admin@example.com', 'long secure password');
        $types = $this->core->container()->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        $this->security->begin(Request::create('http://localhost/admin', cookies: $cookie));
        try {
            try {
                $types->create('bypass', 'Bypass');
                self::fail('Expected explicit schema review.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('separate schema review', $exception->getMessage());
            }
            try {
                $types->schema('article')->apply(true);
                self::fail('Expected forced schema application to be blocked.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('cannot force destructive changes', $exception->getMessage());
            }
        } finally {
            $this->security->end();
        }
        self::assertNull($types->get('bypass'));
    }

    public function testNewFieldOnAppliedTypeRequiresSeparateSchemaAction(): void
    {
        [$cookie] = $this->login('admin@example.com', 'long secure password');
        $new = $this->request('/admin/structure/content-types/article/fields/new', cookies: $cookie);
        self::assertSame(302, $this->request('/admin/structure/content-types/article/fields/new', 'POST', [
            '_csrf' => $this->csrf($new), 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'string', 'length' => '80',
        ], $cookie)->getStatusCode());
        $types = $this->core->container()->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertSame('pending', $types->requireType('article')->schemaStatus);
        self::assertFalse($this->core->database()->connection()->createSchemaManager()->introspectTable('content_article_data')->hasColumn('subtitle'));
        $editField = $this->request('/admin/structure/content-types/article/fields/subtitle', cookies: $cookie);
        self::assertSame(302, $this->request('/admin/structure/content-types/article/fields/subtitle', 'POST', [
            '_csrf' => $this->csrf($editField), 'label' => 'Short subtitle', 'type' => 'string', 'length' => '120',
        ], $cookie)->getStatusCode());
        self::assertSame(120, $types->fields('article')['subtitle']->settings['length']);
        $schema = $this->request('/admin/structure/content-types/article/schema', cookies: $cookie);
        self::assertStringContainsString('Add column', $schema->getContent());
        self::assertStringContainsString('subtitle', $schema->getContent());
        self::assertSame(302, $this->request('/admin/structure/content-types/article/schema/apply', 'POST', ['_csrf' => $this->csrf($schema)], $cookie)->getStatusCode());
        self::assertTrue($this->core->database()->connection()->createSchemaManager()->introspectTable('content_article_data')->hasColumn('subtitle'));
        $form = $this->request('/admin/content/article/new', cookies: $cookie);
        self::assertStringContainsString('name="subtitle"', $form->getContent());
        self::assertSame(302, $this->request('/admin/content/article/new', 'POST', ['_csrf' => $this->csrf($form), 'title' => 'Article', 'subtitle' => 'New field'], $cookie)->getStatusCode());
        self::assertSame('New field', $this->storage->findBy('article', [])[0]->get('subtitle'));
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
