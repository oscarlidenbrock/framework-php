# ADR 0001: Module bootstrap format and activation

Status: Accepted

## Context

The first runtime needs a stable way to discover extension code, select active modules, register services and routes, and create controllers without editing the kernel. The format affects third-party modules and site configuration.

## Options considered

- Put every module in the root Composer autoload map and load all routes and services. This requires editing application Composer metadata for each extension and mixes installation with activation.
- Use a custom SPL autoloader and executable module registration files. This duplicates Composer behavior and executes extension code during discovery.
- Read YAML manifests, add PSR-4 prefixes to Composer's ClassLoader, and load service and route YAML only for enabled modules.

## Decision

Use the third option. Each `module.yaml` declares a lowercase `id`, a case-consistent `namespace` segment, a name, a version and optional `requires` IDs. `config/app.yaml` contains `enabled_modules` and an optional discovery cache flag. Discovery covers one module directory below each of `modules/core`, `modules/contrib` and `modules/custom`. It validates all manifests and namespace collisions, then registers all discovered PHP namespace prefixes. Enabled modules are ordered by dependencies. Their optional `config/services.yaml` files use Symfony DependencyInjection's YAML format; their optional `config/routes.yaml` files map route names to `path`, `methods` and a public controller service ID. The container is compiled after all enabled definitions are loaded.

## Consequences

Installed but disabled modules are discoverable and autoloadable, though their services and routes are inactive. Enabling or disabling a module changes site configuration, not module code. The optional JSON discovery cache avoids reparsing unchanged manifests; it is invalidated by root, module directory or manifest metadata changes and can be removed explicitly during deployment. Current routes are public; access rules and lifecycle operations require later decisions and implementation. No Symfony FrameworkBundle or custom SPL autoloader is involved.
