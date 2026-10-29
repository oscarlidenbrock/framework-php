# Architecture and status

`Core` is the public facade. `Bootstrap` loads site configuration, discovers modules and themes, registers their PHP namespaces through Composer, selects enabled modules and themes, builds Twig lookup paths, loads module service and route files, compiles the container, and creates the HTTP kernel. `HttpKernel` matches a request and invokes a public controller service. Controllers can return a `Response` directly or use the injected `TemplateManager` to render a Twig-backed HTML `Response`. The same `Core` boot pipeline is used by `public/index.php`, `bin/console`, and tests.

| Capability | Status |
| --- | --- |
| Core facade and HTTP entry point | Implemented |
| Module discovery, namespace registration and dependency ordering | Implemented |
| Workspace-scoped database-owned module/theme selection with bootstrap snapshot | Implemented in the `site` module |
| Module services and public routes | Implemented |
| Optional module discovery cache | Implemented |
| Theme discovery, inheritance and separate public/admin rendering | Implemented |
| Twig module templates, theme overrides and layouts | Implemented |
| Twig cache and explicit local asset publication | Implemented |
| Named asset libraries, reusable dependency sets, HTTPS CSS/JS resources and response-local attachment | Implemented |
| Module permissions, route access, accounts and minimal administration | Implemented first slice; lifecycle commands planned |
| Optional named DBAL connections, per-connection ORM and module migrations | Implemented |
| Host-selected workspaces and recursive YAML configuration overrides | Implemented |
| Workspace-scoped site title and email overrides | Implemented in the `site` module; other configuration remains YAML |
| Dynamic scalar content types, system fields and DBAL CRUD | Implemented; basic protected administration UI implemented, advanced fields planned |
| Per-file asset HTML attributes and script placement | Implemented |
| Workspace runtime paths and PSR-3 JSON file logging | Implemented first slice |
| Permission-gated recent log list and detail | Implemented first slice; full archive search planned |
| Theme regions and first block placement UI | Implemented; asset build pipeline planned |
| Configurable content | Implemented first slice; advanced fields and presentation planned |

The kernel does not depend on the example module. Its services, routes and Twig templates are loaded through general registration pipelines. Twig template lookup is separate from PHP autoloading.

`DatabaseRegistry` reads optional `config/database.yaml`, owns lazy `DatabaseManager` instances and assigns enabled module mappings and migrations to named connections. Each manager owns its DBAL connection, optional ORM EntityManager and Doctrine Migrations history. The HTTP kernel clears initialized EntityManagers after each request. See [database.md](database.md) and [decision 0006](decisions/0006-multi-database-doctrine.md).

`WorkspaceResolver` selects a workspace before boot. One `ConfigManager` merges global YAML with the selected workspace's overrides; Bootstrap passes those effective values to modules, themes, Twig and Doctrine. See [workspaces.md](workspaces.md) and [decision 0007](decisions/0007-workspace-configuration.md).

`InstallationSnapshot` gives Bootstrap the database-owned module and theme selection without an early connection. The `site` module validates changes and persists three workspace-scoped rows before atomically replacing the snapshot. New installations use YAML seed values; CLI recovery can restore that seed when a selection prevents administration. See [installation-selection.md](installation-selection.md) and [decision 0015](decisions/0015-persisted-installation-selection.md).
