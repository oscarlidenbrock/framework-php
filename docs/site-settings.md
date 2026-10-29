# General site settings

Enable the `site` module in `config/app.yaml`. Its migration creates `config` on configured connections; run it explicitly in each workspace's selected connection before editing settings:

```sh
APP_WORKSPACE=development php bin/console database:migrate --yes
```

The migration is never run by an HTTP request. The settings screen is `/admin/settings`, rendered with `admin_theme` and added to the sidebar by the `site` module. `site.settings.view` permits reading the screen and `site.settings.edit` permits saving it; assign both to editors. Every POST requires a session CSRF token. An administrator can restore the YAML default for either setting independently.

`config/site.yaml` supplies versioned defaults:

```yaml
site:
  title: Framework
  email: admin@example.com
```

`config/workspaces/{workspace}/site.yaml` can override either default through the ordinary workspace YAML merge. An optional `site.connection` names the DBAL connection used by the settings service; otherwise it uses the workspace's default connection. Database connection configuration and credentials remain in the existing database/environment configuration, never in the `config` table.

For each key, a row in `config` for the active workspace wins over its effective YAML default. Deleting the row restores that default. The composite key `(workspace, setting_key)` isolates two workspaces even when they use the same physical database. An existing row with `value = ''` is an override, distinct from no row. The current title and email validators reject empty values on writes, but the storage representation preserves that distinction for future settings. Title is trimmed and must contain 1–120 visible characters. Email is trimmed, validated as an email address and limited to 254 bytes. Only `title` and `email` are accepted, so this API cannot store secrets or arbitrary deployment configuration.

Other module services can inject `Framework\Config\SiteSettingsInterface` and call `get('title')` or `get('email')`. The `site.settings` service also exposes `default($key)`, `details()` and `save(['title' => 'New name', 'email' => null])`; `null` in `save()` deletes that key's override. The service validates all requested updates before a transaction writes them. `get()` reads the effective value when requested, so later requests see saved changes. The example module passes `get('title')` to a public Twig template, which escapes it. The service remains optional for modules that run without `site`.

Bootstrap selects the workspace, modules, themes and database definitions solely from YAML. It does not read `config`. Without the migration, reads fall back to YAML defaults and writes report the pending migration. A configured database connection that fails to connect still reports its error; failures are not silently treated as missing overrides. CLI `help` and core boot do not require the table. Future default declarations from other modules can use the same `get()` style API after an explicit registration design; this slice does not introduce a general configuration registry. See ADR 0014.

SQLite tests cover the migration, workspace isolation on one database, HTTP permissions, CSRF, validation, reset and public rendering. MySQL/MariaDB schema behavior still needs a live integration run.
