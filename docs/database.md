# Database connections and module persistence

Persistence is optional. The application boots without `config/database.yaml` until an enabled module declares database mappings or migrations. To enable it, copy `config/database.example.yaml` to `config/database.yaml` and provide the URL environment variables through deployment configuration. The local file and credentials are ignored by Git. Workspace overrides in `config/workspaces/{name}/database.yaml` merge before registration. Each connection is registered during boot and opened on first use.

```yaml
database:
  default: primary
  connections:
    primary:
      url_env: DATABASE_URL
      orm: true
    legacy:
      url_env: LEGACY_DATABASE_URL
      orm: true
    analytics:
      params:
        driver: pdo_sqlite
        path: /path/to/analytics.sqlite
      orm: false
```

`default` must name a connection. Names use lowercase letters, digits and underscores and start with a letter. `url_env` names an environment variable whose value is a Doctrine connection URL. Doctrine DBAL driver parameters may be placed directly under a connection or in `params`; nested `params` wins if both specify the same key. They may supplement a URL. `orm` defaults to `false`. An optional `migrations_table` changes that connection's migration history table name. Use paths or DSNs appropriate to the deployment; do not commit credentials. For tests, `params: {driver: pdo_sqlite, memory: true}` creates an independent in-memory SQLite connection. Database configuration is validated at boot; the driver opens lazily.

## DBAL API

`$core->database()` selects the configured default. `$core->database('legacy')` selects a named connection. Both return a stable `DatabaseManager` for the lifetime of the booted application.

```php
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

$db = $core->database('legacy');
$rows = $db->query('SELECT * FROM customers WHERE country = :country', ['country' => 'ES']);
$row = $db->queryOne('SELECT * FROM customers WHERE id = :id', ['id' => 15]);
$value = $db->fetchValue('SELECT COUNT(*) FROM customers');
$changed = $db->execute('UPDATE customers SET active = :active WHERE id = :id', ['active' => 0, 'id' => 15]);
$selected = $db->query('SELECT * FROM customers WHERE id IN (:ids)', ['ids' => [1, 2]], ['ids' => ArrayParameterType::INTEGER]);
$typed = $db->queryOne('SELECT * FROM customers WHERE id = ?', [15], [ParameterType::INTEGER]);
foreach ($db->iterate('SELECT * FROM customers') as $customer) {
    // Process each row without collecting all rows in an array.
}
```

`query()` returns associative rows or `[]`; `queryOne()` returns one associative row or `null`; `fetchValue()` returns the first scalar or `null`. SQL `NULL` also yields `null`, so `fetchValue()` cannot distinguish it from no row. `execute()` returns the affected row count reported by DBAL and the driver. `iterate()` returns an iterable backed by a live result; consume it promptly and avoid holding its connection busy. All methods accept named or positional parameters and an optional Doctrine types array. Use parameters for values; placeholders cannot stand for SQL identifiers. Validate dynamic table or column names against an allowlist and quote them with Doctrine's platform API. Custom SQL portability depends on the dialect. Doctrine exceptions propagate to callers; HTTP handling must not reveal connection details publicly.

Use `$db->connection()` for DBAL APIs and `$db->transaction(fn ($database) => ...)` for a single-connection transaction. The callback commits on success, rolls back and rethrows on failure. Nested transactions and DDL behavior depend on the driver. There is no distributed transaction across connections.

## ORM and module ownership

`$core->database('legacy')->entityManager()` returns that connection's EntityManager; calling it on `orm: false` raises a descriptive exception. Attribute-mapped entity classes in an enabled module's `src/Entity/` are assigned to the module's `database.entity_manager`, or to the configured default. The following manifest assigns its default entities and migrations to `primary`, and a separate mapping and migration group to `legacy`:

```yaml
id: billing
name: Billing
namespace: Billing
version: 1.0.0
requires: []
database:
  entity_manager: primary
  mappings:
    legacy:
      - src/LegacyEntity
  migrations:
    legacy:
      Module\Billing\LegacyMigrations: migrations/legacy
```

The conventional `migrations/` directory uses `Module\Billing\Migrations` and the preferred connection. The explicit groups are for additional directories; paths must exist within the module. Entity mappings cannot overlap or appear in multiple EntityManagers. Cross-connection ORM associations are rejected by `database:validate`; use scalar IDs and application services instead. Modules may define their own repositories without a required base entity class. Metadata caches use separate namespaces per connection under `storage/cache/doctrine/`. The HTTP kernel clears initialized EntityManager identity maps after each request; CLI commands retain theirs for the command lifetime.

Inject `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface` for the default connection. For named connections, reference `database.legacy.connection` or `database.legacy.entity_manager` in a module's `config/services.yaml`. `database.legacy` is its `DatabaseManager` service. Only ORM-enabled connections have an EntityManager service. For example:

```yaml
services:
  billing.repository:
    class: Module\Billing\Repository\CustomerRepository
    arguments: ['@database.legacy.connection']
```

## Migrations and CLI

Migration classes extend Doctrine Migrations `AbstractMigration` and live in a configured module namespace/directory. Only enabled modules contribute migration directories. Each connection has its own migration table and pending version set. Disabling a module does not remove data or run down migrations.

```sh
php bin/console database:status
php bin/console database:status --connection=legacy
php bin/console database:validate --connection=legacy
php bin/console database:migrate --connection=legacy --yes
```

`status` reports pending migrations for one connection. `validate` opens the selected connection and validates enabled ORM mappings across all configured EntityManagers. `migrate` executes only the selected connection's pending migrations and requires `--yes`; it never runs during HTTP boot. Review migrations and back up production data before applying schema changes. Doctrine handles version tracking and execution; DDL transactional support varies by engine. SQLite integration tests cover two independent connections, mappings, repository operations and migrations. MySQL and PostgreSQL drivers can be registered, but no live server integration has been verified in this repository.
