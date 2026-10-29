<?php

declare(strict_types=1);

use Framework\Core;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Display\ContentViewManager;
use Module\Content\Form\ContentFormManager;
use Module\Content\Storage\ContentStorage;
use PHPUnit\Framework\TestCase;

final class ContentFormTest extends TestCase
{
    private string $root;
    private Core $core;
    private ContentTypeManager $types;
    private ContentStorage $storage;
    private ContentFormManager $forms;
    private ContentViewManager $views;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-forms-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/testing', 'modules/core', 'modules/custom', 'themes/core/default'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        symlink(dirname(__DIR__) . '/modules/core/content', $this->root . '/modules/core/content');
        symlink(__DIR__ . '/fixtures/form_extension', $this->root . '/modules/custom/form_extension');
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [content, form_extension]\n");
        file_put_contents($this->root . '/config/content.yaml', "content:\n  connection: default\n  display_modes:\n    full: null\n    summary: [title]\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        file_put_contents($this->root . '/themes/core/default/theme.yaml', "id: default\nname: Default\nnamespace: FormTestTheme\nversion: 0.1.0\nparent: null\n");
        mkdir($this->root . '/themes/core/default/templates/modules/content/display', 0775, true);
        file_put_contents($this->root . '/themes/core/default/templates/modules/content/display/field.html.twig', '<mark data-theme-override="{{ name }}">{{ value ?? \'\' }}</mark>');
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $this->core->database()->migrate();
        $container = $this->core->container();
        $this->types = $container->get('content.types');
        $this->storage = $container->get('content.storage');
        $this->forms = $container->get('content.forms');
        $this->views = $container->get('content.views');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testBuildSubmitSaveEditAndRenderWithCustomExtensions(): void
    {
        $this->types->create('article', 'Article');
        foreach ([
            ['name' => 'body', 'type' => 'text'],
            ['name' => 'price', 'type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'slug', 'type' => 'slug'],
            ['name' => 'event_date', 'type' => 'date'],
            ['name' => 'event_time', 'type' => 'datetime'],
        ] as $field) {
            $this->types->addField('article', $field);
        }
        $this->types->schema('article')->apply();
        $form = $this->forms->build('article');
        $html = $form->render();
        self::assertStringContainsString('name="title"', $html);
        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('type="datetime-local"', $html);
        self::assertStringContainsString('data-widget="slug"', $html);
        self::assertStringNotContainsString('name="owner"', $html);
        self::assertStringNotContainsString('name="created_at"', $html);
        self::assertStringNotContainsString('name="updated_at"', $html);

        $invalid = $form->submit(['title' => ' ', 'price' => 'bad', 'slug' => 'Invalid Slug', 'owner' => 42]);
        self::assertFalse($invalid->isValid());
        self::assertSame(['owner', 'title', 'price', 'slug'], array_keys($invalid->errors));
        self::assertStringContainsString('field-error', $form->render(['title' => ' '], $invalid->errors));

        $result = $form->submit(['title' => '<Article>', 'body' => '<script>alert(1)</script>', 'price' => '12.50', 'slug' => 'first-post', 'event_date' => '2026-10-29', 'event_time' => '2026-10-29T12:30']);
        self::assertTrue($result->isValid());
        $record = $result->record;
        $this->storage->save($record);
        self::assertFalse($record->get('published'));
        self::assertSame('12.50', $record->get('price'));
        self::assertSame(0, $record->get('owner'));
        self::assertStringContainsString('data-formatter="slug"', $this->views->render($record));
        self::assertStringContainsString('data-theme-override="title"', $this->views->render($record));
        self::assertStringContainsString('&lt;script&gt;', $this->views->render($record));
        self::assertStringNotContainsString('<script>', $this->views->render($record));
        $summary = $this->views->render($record, 'summary');
        self::assertStringContainsString('&lt;Article&gt;', $summary);
        self::assertStringNotContainsString('first-post', $summary);

        $edit = $this->forms->build('article', $record);
        $changed = $edit->submit(['title' => 'Changed']);
        self::assertTrue($changed->isValid());
        self::assertSame('first-post', $changed->record->get('slug'));
        self::assertFalse($changed->record->get('published'));
        $this->storage->save($changed->record);
        self::assertSame('Changed', $this->storage->find('article', $record->id())->get('title'));
        $published = $this->forms->build('article', $changed->record)->submit(['published' => '1']);
        self::assertTrue($published->record->get('published'));
        $this->storage->save($published->record);
        self::assertFalse($this->forms->build('article', $published->record)->submit([])->record->get('published'));
        $this->expectException(InvalidArgumentException::class);
        $this->views->render($record, 'missing');
    }

    public function testAbsentNullEmptyAndUncheckedValues(): void
    {
        $this->types->create('page', 'Page');
        $this->types->addField('page', ['name' => 'count', 'type' => 'integer', 'default' => 7]);
        $this->types->addField('page', ['name' => 'note', 'type' => 'text']);
        $this->types->schema('page')->apply();
        $form = $this->forms->build('page');
        $result = $form->submit(['title' => 'Page', 'note' => '']);
        self::assertTrue($result->isValid());
        self::assertSame(7, $result->record->get('count'));
        self::assertSame('', $result->record->get('note'));
        self::assertFalse($result->record->get('published'));
        $this->storage->save($result->record);
        $edit = $this->forms->build('page', $result->record);
        self::assertSame(7, $edit->submit(['title' => 'Page again'])->record->get('count'));
        self::assertNull($edit->submit(['title' => 'Page', 'count' => null])->record->get('count'));
        self::assertNull($edit->submit(['title' => 'Page', 'count' => ''])->record->get('count'));
        self::assertNull($edit->submit(['title' => 'Page', 'note' => null])->record->get('note'));
        self::assertSame('', $edit->submit(['title' => 'Page', 'note' => ''])->record->get('note'));
        self::assertArrayHasKey('published', $edit->submit(['title' => 'Page', 'published' => 'false'])->errors);
        self::assertArrayHasKey('title', $form->submit([])->errors);
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
