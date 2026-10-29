# ADR 0015: Persisted installation selection and bootstrap snapshot

Status: accepted

## Context

Bootstrap must select modules before it can register their migrations, services, routes and permissions. It must select themes before building Twig and asset libraries. The current database registry is built from the enabled modules, so reading installation choices from the database during bootstrap would introduce a dependency cycle. Basic CLI help must also work before a database exists.

## Options considered

- Query the database during every bootstrap using a separate early connection. This gives immediate consistency but duplicates connection setup, makes every request depend on database availability, and requires a special path before module migrations are known.
- Store the chosen state in a workspace-specific bootstrap snapshot, generated from the persisted selection. This keeps the existing bootstrap order and allows a previously installed site to start while the database is temporarily unavailable.
- Keep YAML as the runtime source and write edits back to YAML. This conflicts with the requested database ownership and makes runtime writes to versioned deployment configuration necessary.

## Decision

The existing `site` module's `config` table stores three installation keys per workspace: `installation.enabled_modules` as a JSON list, `installation.active_theme`, and `installation.admin_theme`. The table remains keyed by `(workspace, setting_key)` so workspaces sharing a connection cannot cross-read selections. Manifests remain the source of extension metadata. Effective workspace YAML is the seed for a new installation, when none of the three rows exists. A partially populated selection is invalid.

The core reads `storage/cache/installation/{workspace}.json` before resolving enabled modules or themes. It never opens a database connection for that read. The snapshot contains only the three selected values and is validated on load. An administrative or CLI change first validates the entire candidate against discovered modules, dependency ordering, theme inheritance, available libraries and database mappings. It persists all three values in a transaction, then atomically replaces the snapshot. The new selection takes effect on the next Core bootstrap; the current request keeps its original container. Discovery caches remain independently invalidated by manifest stamps. Snapshot writes replace the file instead of mutating a compiled container. Out-of-band database changes require `installation:refresh` to validate the rows and rebuild the snapshot.

The precedence for the three selection keys is: persisted snapshot, then the effective YAML seed. Within the seed, a temporary `app.yaml` override passed to Core follows the workspace override, which follows global YAML. An effective `modules.yaml` `enabled_modules` list takes precedence over the app document's list. The snapshot overlays both app and modules documents so all consumers see the installed selection. Temporary YAML is intended for an uninstalled fixture or recovery seed; it cannot silently override a persisted installation.

An uninstalled site without a snapshot starts from YAML defaults without querying the database. CLI `help` never needs the database. The selection management UI and write commands require the explicit `site` migration and a configured connection. Database credentials and connection definitions remain in deployment configuration, never in this table or snapshot. If the database is unavailable after installation, a valid snapshot can still bootstrap, although database-backed operations fail normally.

The `site` and `admin` modules are protected from disabling through the administrative interface to preserve the management route. CLI `installation:recover` removes a broken snapshot so validated YAML defaults boot without opening the database; it does not change persisted rows. CLI `installation:reset --yes` explicitly replaces persisted rows with the YAML seed when the database is available. Alternatively, an operator can repair database rows out of band and use `installation:refresh` to validate and rebuild the snapshot. Normal edits reject rows that differ from the current snapshot. A missing or invalid selected theme fails bootstrap visibly rather than silently selecting another theme.

## Consequences

This changes the earlier rule that module and theme selection only came from YAML. It does not change the rule against database access during bootstrap. A snapshot may become stale when the table is edited outside supported commands; `installation:refresh` is the explicit repair path. A failed snapshot replacement after a committed database write must be reported and repaired by refresh. Persistent PHP processes must construct a new Core to see a new selection. Module enablement does not run migrations or publish assets during HTTP; the operator performs those explicit CLI steps separately.
