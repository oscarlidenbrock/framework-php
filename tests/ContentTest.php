<?php

declare(strict_types=1);

use Framework\Core;
use Framework\Entity\EntityTypeRegistry;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentStorage;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    private string $root;
    private Core $core;
    private ContentTypeManager $types;
    private ContentStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-content-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/testing', 'modules/core', 'themes/core/default'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        symlink(dirname(__DIR__) . '/modules/core/content', $this->root . '/modules/core/content');
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "localhost: testing\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [content]\n");
        file_put_contents($this->root . '/config/content.yaml', "content:\n  connection: default\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        file_put_contents($this->root . '/themes/core/default/theme.yaml', "id: default\nname: Default\nnamespace: ContentTestTheme\nversion: 0.1.0\nparent: null\n");
        $this->core = new Core($this->root, workspace: 'testing');
        $this->core->boot();
        $this->core->database()->migrate();
        $types = $this->core->container()->get('content.types');
        $storage = $this->core->container()->get('content.storage');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertInstanceOf(ContentStorage::class, $storage);
        $this->types = $types;
        $this->storage = $storage;
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testArticleSchemaAndCrud(): void
    {
        $type = $this->types->create('article', 'Article');
        self::assertSame('applied', $type->schemaStatus);
        $registry = $this->core->container()->get(EntityTypeRegistry::class);
        self::assertInstanceOf(EntityTypeRegistry::class, $registry);
        self::assertSame('article', $registry->get('default', 'article')->id());
        self::assertSame(['title', 'created_at', 'updated_at', 'published', 'owner'], array_keys($this->types->fields('article')));
        $record = $this->storage->create('article', ['title' => 'First']);
        $this->storage->save($record);
        self::assertNotNull($record->id());
        self::assertSame('First', $record->get('title'));
        self::assertFalse($record->get('published'));
        self::assertSame(0, $record->get('owner'));
        self::assertEquals($record->get('created_at'), $record->get('updated_at'));
        self::assertCount(1, $this->storage->findBy('article', ['published' => false]));
        $created = $record->get('created_at');
        $updated = $record->get('updated_at');
        usleep(1000);
        $record->set('title', 'Changed');
        $this->storage->save($record);
        self::assertSame('Changed', $this->storage->find('article', $record->id())->get('title'));
        self::assertEquals($created, $record->get('created_at'));
        self::assertGreaterThan($updated, $record->get('updated_at'));
        $this->storage->delete($record);
        self::assertNull($this->storage->find('article', $record->id()));
    }

    public function testArticleWithCustomFieldsAndTitleLength(): void
    {
        $this->types->create('article', 'Article');
        $this->types->addField('article', ['name' => 'body', 'type' => 'text']);
        $this->types->addField('article', ['name' => 'price', 'type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]]);
        $this->types->updateField('article', 'title', ['settings' => ['length' => 50]]);
        self::assertCount(7, $this->types->fields('article'));
        self::assertNotEmpty($this->types->schema('article')->diff());
        $this->expectException(LogicException::class);
        $this->storage->save($this->storage->create('article', ['title' => 'Pending']));
    }

    public function testSchemaApplyAndCustomValues(): void
    {
        $this->types->create('article', 'Article');
        $this->types->addField('article', ['name' => 'body', 'type' => 'text']);
        $this->types->addField('article', ['name' => 'price', 'type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]]);
        $this->types->updateField('article', 'title', ['settings' => ['length' => 50]]);
        $this->types->schema('article')->apply(true);
        self::assertSame([], $this->types->schema('article')->diff());
        $schema = $this->core->database()->connection()->createSchemaManager();
        self::assertTrue($schema->tablesExist(['content_types', 'content_article_fields', 'content_article_data']));
        $table = $schema->introspectTable('content_article_data');
        self::assertSame(50, $table->getColumn('title')->getLength());
        self::assertTrue($table->hasColumn('body'));
        self::assertTrue($table->hasColumn('price'));
        self::assertTrue($table->hasIndex('idx_article_published'));
        self::assertTrue($table->hasIndex('idx_article_owner'));
        self::assertTrue($table->hasIndex('idx_article_created'));
        self::assertSame(7, (int) $this->core->database()->fetchValue('SELECT COUNT(*) FROM content_article_fields'));
        self::assertSame(5, (int) $this->core->database()->fetchValue('SELECT COUNT(*) FROM content_article_fields WHERE is_system = 1'));
        $record = $this->storage->create('article', ['title' => 'Article', 'body' => 'Content', 'price' => '12.50']);
        $this->storage->save($record);
        self::assertSame('12.50', $this->storage->find('article', $record->id())->get('price'));
        $minimal = $this->storage->create('article', ['title' => 'Only title']);
        $this->storage->save($minimal);
        self::assertNull($minimal->get('body'));
        self::assertNull($minimal->get('price'));
        self::assertFalse($minimal->get('published'));
    }

    public function testCliCreatesAndUpdatesContentType(): void
    {
        mkdir($this->root . '/bin');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default:\n      driver: pdo_sqlite\n      path: " . $this->root . "/content.sqlite\n      orm: false\n");
        foreach ([
            ['database:migrate', '--yes'],
            ['content:type:create', 'article', 'Article'],
            ['content:field:add', 'article', 'body', 'text'],
            ['content:field:add', 'article', 'price', 'decimal', '--precision=10', '--scale=2'],
            ['content:field:update', 'article', 'title', '--length=50'],
        ] as $arguments) {
            [$code, $output] = $this->console($arguments);
            self::assertSame(0, $code, $output);
        }
        [$code, $output] = $this->console(['content:schema:diff', 'article']);
        self::assertSame(0, $code);
        self::assertStringContainsString('change_column', $output);
        [$code, $output] = $this->console(['content:schema:apply', 'article']);
        self::assertSame(1, $code);
        self::assertStringContainsString('require confirmation', $output);
        [$code, $output] = $this->console(['content:schema:apply', 'article', '--yes']);
        self::assertSame(0, $code, $output);
        [$code, $output] = $this->console(['content:field:list', 'article']);
        self::assertSame(0, $code);
        self::assertStringContainsString("price\tdecimal\tcustom", $output);
    }

    public function testSystemFieldsAndSchemaChangesAreProtected(): void
    {
        $this->types->create('article', 'Article');
        foreach (['title', 'created_at', 'updated_at', 'published', 'owner'] as $name) {
            self::assertTrue($this->types->fields('article')[$name]->system);
            try {
                $this->types->removeField('article', $name);
                self::fail('Expected protected system field.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('System fields', $exception->getMessage());
            }
        }
        try {
            $this->types->addField('article', ['name' => 'title', 'type' => 'text']);
            self::fail('Expected protected title field.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('System fields', $exception->getMessage());
        }
        try {
            $this->types->updateField('article', 'title', ['required' => false]);
            self::fail('Expected immutable title requirement.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Title may change', $exception->getMessage());
        }
        try {
            $this->types->updateField('article', 'title', ['name' => 'headline']);
            self::fail('Expected immutable field name.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be changed', $exception->getMessage());
        }
    }

    public function testValidationAndNoopUpdate(): void
    {
        $this->types->create('article', 'Article');
        foreach (['', '   '] as $title) {
            $record = $this->storage->create('article', ['title' => $title]);
            try {
                $this->storage->save($record);
                self::fail('Expected invalid title.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Title cannot be empty', $exception->getMessage());
            }
        }
        $record = $this->storage->create('article', ['title' => 'Valid']);
        $this->storage->save($record);
        $before = $record->get('updated_at');
        $this->storage->save($record);
        self::assertEquals($before, $record->get('updated_at'));
        $record->set('published', true);
        $this->storage->save($record);
        self::assertTrue($record->get('published'));
        self::assertSame(0, $record->get('owner'));
        self::assertSame([], $this->storage->findBy('article', ['title' => "Valid' OR 1=1 --"]));
        try {
            $record->set('owner', 5);
            self::fail('Expected managed owner rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('managed', $exception->getMessage());
        }
        $this->expectExceptionMessage('Unknown or managed content field');
        $this->storage->create('article', ['title' => 'X', 'unknown' => 'value']);
    }

    public function testDestructiveChangeRefusesPopulatedTable(): void
    {
        $this->types->create('article', 'Article');
        $record = $this->storage->create('article', ['title' => 'Stored']);
        $this->storage->save($record);
        $this->types->updateField('article', 'title', ['settings' => ['length' => 50]]);
        $changes = $this->types->schema('article')->diff();
        self::assertTrue($changes[0]['destructive']);
        $this->expectExceptionMessage('require a data migration');
        $this->types->schema('article')->apply(true);
    }

    public function testSchemaDriftBlocksWritesAndAutomaticColumnRemoval(): void
    {
        $this->types->create('article', 'Article');
        $this->core->database()->execute('ALTER TABLE content_article_data ADD COLUMN unexpected TEXT');
        $changes = $this->types->schema('article')->diff();
        self::assertSame('drop_column', $changes[0]['kind']);
        try {
            $this->storage->save($this->storage->create('article', ['title' => 'Blocked']));
            self::fail('Expected drift protection.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('drifted', $exception->getMessage());
        }
        $this->expectExceptionMessage('manual data migration');
        $this->types->schema('article')->apply(true);
    }

    public function testCustomFieldTypeChangeRequiresConfirmation(): void
    {
        $this->types->create('article', 'Article');
        $this->types->addField('article', ['name' => 'stock', 'type' => 'text']);
        $this->types->schema('article')->apply();
        $this->types->updateField('article', 'stock', ['type' => 'integer']);
        $changes = $this->types->schema('article')->diff();
        self::assertSame('change_column', $changes[0]['kind']);
        self::assertTrue($changes[0]['destructive']);
        try {
            $this->types->schema('article')->apply();
            self::fail('Expected confirmation requirement.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('require confirmation', $exception->getMessage());
        }
        self::assertSame('pending', $this->types->get('article')->schemaStatus);
        $this->types->schema('article')->apply(true);
        self::assertSame([], $this->types->schema('article')->diff());
    }

    public function testInterruptedSchemaCreationCanBeRepaired(): void
    {
        $this->types->create('article', 'Article');
        $schemaManager = $this->core->database()->connection()->createSchemaManager();
        $schemaManager->dropTable('content_article_fields');
        self::assertSame('create_fields_table', $this->types->schema('article')->diff()[0]['kind']);
        $this->types->schema('article')->apply();
        self::assertSame([], $this->types->schema('article')->diff());
        self::assertCount(5, $this->types->fields('article'));
    }

    public function testAllBuiltInFieldTypesPersistAndHydrate(): void
    {
        $this->types->create('article', 'Article');
        foreach ([
            ['name' => 'summary', 'type' => 'string', 'settings' => ['length' => 80]],
            ['name' => 'body', 'type' => 'text'],
            ['name' => 'stock', 'type' => 'integer'],
            ['name' => 'external_id', 'type' => 'bigint'],
            ['name' => 'price', 'type' => 'decimal', 'settings' => ['precision' => 20, 'scale' => 2]],
            ['name' => 'ratio', 'type' => 'float'],
            ['name' => 'featured', 'type' => 'boolean'],
            ['name' => 'event_date', 'type' => 'date'],
            ['name' => 'event_time', 'type' => 'datetime'],
        ] as $field) {
            $this->types->addField('article', $field);
        }
        $this->types->schema('article')->apply();
        $record = $this->storage->create('article', [
            'title' => 'Typed article', 'summary' => 'Summary', 'body' => 'Body', 'stock' => 4,
            'external_id' => 4000000000, 'price' => '123456789012345678.90', 'ratio' => 1.5,
            'featured' => true, 'event_date' => '2026-10-29',
            'event_time' => '2026-10-29 12:30:00.000000',
        ]);
        $this->storage->save($record);
        $loaded = $this->storage->find('article', $record->id());
        self::assertSame('123456789012345678.90', $loaded->get('price'));
        self::assertSame(4000000000, $loaded->get('external_id'));
        self::assertSame(4, $loaded->get('stock'));
        self::assertSame(1.5, $loaded->get('ratio'));
        self::assertTrue($loaded->get('featured'));
        self::assertInstanceOf(DateTimeImmutable::class, $loaded->get('event_date'));
        self::assertSame('2026-10-29', $loaded->get('event_date')->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $loaded->get('event_time'));
        self::assertSame('2026-10-29 12:30:00', $loaded->get('event_time')->format('Y-m-d H:i:s'));
    }

    public function testInvalidNamespacesDuplicatesAndUnknownFiltersAreRejected(): void
    {
        foreach (['system', '../escape', 'article;DROP'] as $name) {
            try {
                $this->types->create($name, 'Invalid');
                self::fail('Expected invalid namespace.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('namespace', $exception->getMessage());
            }
        }
        $this->types->create('article', 'Article');
        try {
            $this->types->create('article', 'Duplicate');
            self::fail('Expected duplicate rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->expectExceptionMessage('Unknown filter field');
        $this->storage->findBy('article', ['title;DROP TABLE content_article_data' => 'x']);
    }

    public function testContentTypesAreIndependentAcrossConnections(): void
    {
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: default\n  connections:\n    default: {driver: pdo_sqlite, memory: true, orm: false}\n    legacy: {driver: pdo_sqlite, memory: true, orm: false}\n");
        file_put_contents($this->root . '/config/workspaces/testing/content.yaml', "content:\n  connection: legacy\n");
        $core = new Core($this->root, workspace: 'testing');
        $core->boot();
        $core->database('default')->migrate();
        $core->database('legacy')->migrate();
        $types = $core->container()->get('content.types');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertSame('legacy', $types->create('article', 'Legacy Article')->connection);
        self::assertSame('default', $types->create('article', 'Default Article', connection: 'default')->connection);
        self::assertSame('Legacy Article', $types->get('article')->label);
        self::assertSame('Default Article', $types->get('article', 'default')->label);
        self::assertNotSame($core->database('default')->connection(), $core->database('legacy')->connection());
    }

    public function testEnabledModuleRegistersCustomFieldTypeThroughContainerTag(): void
    {
        mkdir($this->root . '/modules/custom/commerce/src/Field', 0775, true);
        mkdir($this->root . '/modules/custom/commerce/config', 0775, true);
        file_put_contents($this->root . '/modules/custom/commerce/module.yaml', "id: commerce\nname: Commerce\nnamespace: Commerce\nversion: 0.1.0\nrequires: [content]\n");
        file_put_contents($this->root . '/modules/custom/commerce/config/services.yaml', "services:\n  commerce.slug_field:\n    class: Module\\Commerce\\Field\\SlugFieldType\n    tags: ['framework.field_type']\n");
        file_put_contents($this->root . '/modules/custom/commerce/src/Field/SlugFieldType.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Module\Commerce\Field;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeInterface;
use Framework\Field\ScalarFieldType;
final class SlugFieldType implements FieldTypeInterface
{
    private ScalarFieldType $string;
    public function __construct() { $this->string = new ScalarFieldType('string'); }
    public function id(): string { return 'slug'; }
    public function validateSettings(array $settings): void { $this->string->validateSettings($settings); }
    public function normalize(mixed $value, FieldDefinition $field): mixed { $normalized = $this->string->normalize($value, $field); return $normalized === null ? null : strtolower($normalized); }
    public function toDatabase(mixed $value, FieldDefinition $field): mixed { return $this->normalize($value, $field); }
    public function fromDatabase(mixed $value, FieldDefinition $field): mixed { return $value; }
    public function addColumn(Table $table, FieldDefinition $field, AbstractPlatform $platform): void { $this->string->addColumn($table, $field, $platform); }
}
PHP);
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [content, commerce]\n");
        $core = new Core($this->root, workspace: 'testing');
        $core->boot();
        $core->database()->migrate();
        $types = $core->container()->get('content.types');
        $storage = $core->container()->get('content.storage');
        self::assertInstanceOf(ContentTypeManager::class, $types);
        self::assertInstanceOf(ContentStorage::class, $storage);
        $types->create('article', 'Article');
        $types->addField('article', ['name' => 'slug', 'type' => 'slug']);
        $types->schema('article')->apply();
        $record = $storage->create('article', ['title' => 'Custom', 'slug' => 'MY-SLUG']);
        $storage->save($record);
        self::assertSame('my-slug', $storage->find('article', $record->id())->get('slug'));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
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

    /** @param list<string> $arguments
     *  @return array{int, string}
     */
    private function console(array $arguments): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/bin/console', '--workspace=testing', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start CLI fixture.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
