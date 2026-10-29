# ADR 0014: Workspace-scoped site setting overrides

## Context

Workspace YAML must resolve connection definitions, enabled modules and themes before any database access. Editors need to change the site title and contact email without editing deployment files. Two workspaces may share one relational connection.

## Decision

The `site` core module owns an explicit Doctrine migration for `config`, keyed by `(workspace, setting_key)` with a non-null text value and update timestamp. The effective setting is the row value when a row exists, otherwise the effective workspace YAML default from `config/site.yaml`. A row containing an empty string is distinct from no row; deleting a row restores the YAML default. The settings service accepts its defaults after Bootstrap has resolved the workspace and database registry. It reads the table only when a setting is requested, never during workspace selection, module activation, theme selection or container compilation. If no connection is configured or the migration has not run, read access returns YAML defaults; writes require the configured connection and migrated table.

Only `title` and `email` are accepted in this slice. The module validates both before persisting. Credentials, connection definitions, module activation and theme selection remain YAML/environment configuration and are not eligible for this table. Other modules can consume the small service API; adding further default owners later requires an explicit registration design rather than an unbounded key/value configuration system now.

## Consequences

Shared databases keep workspaces isolated, and the same workspace can choose a different connection through YAML. Reads remain usable during initial installation without a database, while database failures after installation are not silently hidden. The table needs an explicit migration on the selected connection. Existing bootstrap and CLI help paths do not depend on it.
