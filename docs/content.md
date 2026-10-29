# Dynamic content types and fields

The core module `content` owns the CMS content model. Reusable contracts and field types live in `core/Entity/` and `core/Field/`; dynamic definitions and values live in relational tables through Doctrine DBAL. Code-defined PHP entities still use Doctrine ORM independently. No PHP entity class or ORM mapping is generated for a content type.

Enable `content` in `config/app.yaml` or `config/modules.yaml`, configure a relational connection, then run its general migration on that connection:

```sh
APP_WORKSPACE=development php bin/console database:migrate --yes
```

The module migration is registered on each configured connection. It creates `content_types`, which stores a type's namespace, label, description, optional owning module, connection name, schema status and creation/update timestamps. The default connection for new types is `content.connection` in `config/content.yaml`; a workspace may override it. CLI `--connection=name` and `ContentTypeManager::create(..., connection: 'name')` select another connection. Each connection has independent definitions, tables and migration history. YAML controls the selected connection, not the persisted type definitions.

## Tables and system fields

Creating `article` explicitly creates `content_article_fields` and `content_article_data`. The former stores field name, label, type, system/required flags, length, precision, scale, typed JSON default, settings, weight and timestamps. The latter stores `id` and one SQL column per scalar field. Validated namespaces use lowercase letters, digits and underscores, begin with a letter and have at most 30 characters. Table names derive only from validated namespaces. Renaming a namespace is not supported.

Every Content Type must include the mandatory system fields `title`, `created_at`, `updated_at`, `published` and `owner`. These fields are protected and automatically managed through the Field API and Content Storage.

`title` is required, nonblank and defaults to length 255; its label and length may be changed. `created_at` and `updated_at` are UTC `DateTimeImmutable` values set to the same instant on insertion. A changed record receives a new `updated_at`; a no-op save preserves it. `published` defaults to false and has an index. `owner` defaults to anonymous ID 0, cannot be negative and has an index. Without an authentication module, callers cannot assign or change another owner through Content Storage. `created_at` also has an index. System fields cannot be removed, renamed, replaced or made optional. No user with ID 0 or mandatory user foreign key is created.

Supported scalar field types are `string`, `text`, `integer`, `bigint`, `decimal`, `float`, `boolean`, `date` and `datetime`. A string accepts `settings.length` (default 255); a decimal accepts `settings.precision` and `settings.scale` (defaults 10 and 0). Decimal values should be supplied as strings to avoid PHP floating-point loss. SQLite uses a text-affinity column for decimals to preserve their exact string representation; MySQL uses DECIMAL. Values are validated in PHP as well as by database constraints. Optional fields accept null. Custom defaults are stored as JSON in field definitions, preserving booleans and numbers.

## Define and synchronize an article

```sh
APP_WORKSPACE=development php bin/console content:type:create article "Article"
APP_WORKSPACE=development php bin/console content:field:add article body text
APP_WORKSPACE=development php bin/console content:field:add article price decimal --precision=10 --scale=2
APP_WORKSPACE=development php bin/console content:field:update article title --length=50
APP_WORKSPACE=development php bin/console content:schema:diff article
APP_WORKSPACE=development php bin/console content:schema:apply article --yes
APP_WORKSPACE=development php bin/console content:field:list article
```

`content:type:list`, `content:schema:status` and `content:field:list` inspect persisted metadata. Adding or updating a field changes definitions and marks the schema pending; it does not run ALTER TABLE. `ContentSchemaManager::diff()` uses Doctrine DBAL's schema introspection and comparator to report added/changed columns and indexes. `apply()` runs explicit DDL, verifies the physical result and marks the type applied. A dangerous change needs `--yes`. A dangerous change on a populated table, an unexpected physical column or field deletion requires a deliberate data migration; the automatic operation refuses to remove data. Failed DDL leaves an error status for inspection and retry. Schema application uses a state transition to prevent two applications of the same type at once. DDL transaction guarantees vary by driver.

Use the same `--connection=name` for all commands belonging to a type on a secondary connection. No content schema change runs during an HTTP request merely because definitions differ.

## Storage API

Module services can inject `content.types` (`ContentTypeManager`) and `content.storage` (`ContentStorage`). `ContentRecord` is a value holder; persistence stays in Content Storage.

```php
$article = $storage->create('article', ['title' => 'First article', 'published' => true]);
$storage->save($article);
$same = $storage->find('article', $article->id());
$published = $storage->findBy('article', ['published' => true]);
$same->set('title', 'Revised article');
$storage->save($same);
$storage->delete($same);
```

Storage rejects unknown fields and filters, validates values and defaults, uses prepared parameters, and refuses writes when definitions are pending or the physical schema has drifted. Identifiers come only from validated definitions and are quoted through DBAL. It preserves owner and creation time on updates. `findBy()` provides equality and NULL filters; richer queries can be added later without changing the storage schema.

## Extension points and limits

An enabled module may register a service implementing `Framework\Field\FieldTypeInterface` with the `framework.field_type` tag in `config/services.yaml`. The registry validates unique type IDs and injects tagged services before first use. The interface covers settings validation, PHP/database conversion and DBAL column creation. A fixture in `tests/ContentTest.php` registers and uses a custom `slug` field type without editing the core.

## Forms, widgets and formatters

When `content` is enabled, inject `content.forms` (`ContentFormManager`) and `content.views` (`ContentViewManager`). Form construction reads the current field definitions. `created_at`, `updated_at` and `owner` are never editable. The form renders a Twig field fragment; it does not publish a write route or include a `<form>` element. An eventual HTTP handler must supply access control, CSRF protection and a suitable form element before accepting writes.

```php
$form = $forms->build('article');
$html = $form->render();
$result = $form->submit(['title' => 'Example', 'published' => '1']);
if ($result->isValid()) {
    $storage->save($result->record);
} else {
    $html = $form->render(['title' => 'Example'], $result->errors);
}

$edit = $forms->build('article', $storage->find('article', 1));
$result = $edit->submit(['title' => 'Revised']);
$view = $views->render($result->record, 'summary');
```

`submit()` returns a `ContentFormResult` with field-keyed errors and a record only when valid. It does not save. Widgets convert control values, then the existing Field Type validates each value. Content Storage validates again on save. A missing value on creation uses its field default; a missing non-checkbox value on edit preserves the stored value; a missing checkbox means false. Empty text remains an empty string; empty numeric/date controls become null; explicit null remains null. Required values and invalid conversions report field errors. Managed or unknown submitted keys are rejected. The returned edit record is separate from the original, so invalid submissions do not mutate a persisted object.

`config/content.yaml` defines `content.display_modes`: `full: null` displays every field, while `summary: [title]` displays only title. Workspace overrides can replace these lists. `ContentViewManager::render($record, 'full'|'summary')` selects fields and Twig escapes labels and values. Module/theme template overrides use the existing `@content` lookup precedence. No display configuration is stored in Content Type tables.

An enabled module can tag services implementing `Framework\Field\WidgetInterface` with `framework.field_widget`, and services implementing `Framework\Field\FormatterInterface` with `framework.field_formatter`. Their IDs match a Field Type ID; duplicates and unknown IDs fail clearly. The fixture module in `tests/fixtures/form_extension/` supplies a `slug` Field Type, widget and formatter with its own Twig templates, without modifying framework code. Built-in widgets cover text, textarea, number, checkbox, date and date/time controls. Widgets needing CSS or JavaScript must use `AssetManager::attachLibrary()` with a library declared in their module's `config/libraries.yaml` when composing an HTTP response; never emit asset tags from a widget template. The built-in widgets require no assets.

This phase has no admin UI, HTTP write endpoint, permissions, CSRF integration, authenticated ownership, multivalue fields, references, revisions or translations. Field and namespace renames and automatic column deletion are deferred. SQLite integration tests exercise schema changes, forms and CRUD; MySQL/MariaDB behavior still needs live integration verification. SQL dialect and DDL behavior remain driver-dependent.
