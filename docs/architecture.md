# Architecture and status

`Core` is the public facade. `Bootstrap` loads site configuration, discovers modules, registers every discovered module namespace through Composer, selects and orders enabled modules, loads their service and route files, compiles the container, and creates the HTTP kernel. `HttpKernel` matches a request and invokes a public controller service. The same `Core` boot pipeline is used by `public/index.php`, `bin/console`, and tests.

| Capability | Status |
| --- | --- |
| Core facade and HTTP entry point | Implemented |
| Module discovery, namespace registration and dependency ordering | Implemented |
| Configuration-based module activation | Implemented |
| Module services and public routes | Implemented |
| Optional module discovery cache | Implemented |
| Module lifecycle commands, migrations and permissions | Planned |
| Themes and Twig rendering | Planned |
| Database and configurable content | Planned |

The kernel does not depend on the example module. Its service and route files are loaded through the general module registration pipeline. Themes and template lookup will remain separate from PHP autoloading.
