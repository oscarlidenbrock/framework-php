# Architecture and status

`Core` is the public facade. `Bootstrap` loads site configuration, discovers modules and themes, registers their PHP namespaces through Composer, selects enabled modules and themes, builds Twig lookup paths, loads module service and route files, compiles the container, and creates the HTTP kernel. `HttpKernel` matches a request and invokes a public controller service. Controllers can return a `Response` directly or use the injected `TemplateManager` to render a Twig-backed HTML `Response`. The same `Core` boot pipeline is used by `public/index.php`, `bin/console`, and tests.

| Capability | Status |
| --- | --- |
| Core facade and HTTP entry point | Implemented |
| Module discovery, namespace registration and dependency ordering | Implemented |
| Configuration-based module activation | Implemented |
| Module services and public routes | Implemented |
| Optional module discovery cache | Implemented |
| Theme discovery, inheritance and separate public/admin selection | Implemented; admin rendering planned |
| Twig module templates, theme overrides and layouts | Implemented |
| Twig cache and explicit CSS/JS publication | Implemented |
| Named asset libraries, dependencies and response-local attachment | Implemented |
| Module lifecycle commands and permissions | Planned |
| Optional named DBAL connections, per-connection ORM and module migrations | Implemented |
| Per-file asset HTML attributes and script placement | Implemented |
| Theme regions, block placement and asset build pipeline | Planned |
| Configurable content | Planned |

The kernel does not depend on the example module. Its services, routes and Twig templates are loaded through general registration pipelines. Twig template lookup is separate from PHP autoloading.

`DatabaseRegistry` reads optional `config/database.yaml`, owns lazy `DatabaseManager` instances and assigns enabled module mappings and migrations to named connections. Each manager owns its DBAL connection, optional ORM EntityManager and Doctrine Migrations history. The HTTP kernel clears initialized EntityManagers after each request. See [database.md](database.md) and [decision 0006](decisions/0006-multi-database-doctrine.md).
