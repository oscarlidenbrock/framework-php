# AGENTS.md — PHP Modular Framework / CMS

## 1. Mission and authority

This repository is a **standalone PHP framework and modular CMS**, inspired by the modular architecture and content modeling of Drupal and the administrative simplicity of WordPress.

It is **not a Symfony application** and must not be converted into one. It may use **standalone Symfony components** (for example, DependencyInjection, HttpFoundation, Routing, EventDispatcher, Console, Yaml or Cache) where useful, along with Twig, Doctrine and other well-maintained Composer libraries. Symfony FrameworkBundle, Symfony application recipes, a Symfony kernel and Symfony bundles are **not architectural requirements**. Do not introduce them implicitly.

Build a reusable system for websites and web applications, not a one-off site. Its defining capabilities are:

- A small, stable framework kernel.
- Independently installable/configurable modules.
- Independently selectable themes with Twig-based rendering.
- Code-defined entities and administratively configurable content types and fields.
- A simple administrative experience backed by extensible CMS capabilities.
- Users, roles, granular permissions, menus, blocks and friendly URLs.
- Database portability, with MySQL/MariaDB as the primary deployment target.

**Follow the existing repository state and the user's current request.** This file defines intended architecture, not a claim that any directory, feature, dependency or command already exists. Never report planned capabilities as implemented.

### Instruction priority

1. User's explicit task and constraints.
2. This `AGENTS.md`.
3. Accepted architecture decision records (`docs/decisions/`).
4. Existing repository conventions and source code.
5. General best practices.

When a request conflicts with an architectural invariant, explain the conflict and implement the smallest compatible solution, or request a deliberate architectural decision before a breaking redesign.

## 2. Product boundaries

Keep these concepts distinct:

- **Core / Kernel:** bootstrapping, service container, configuration loading, module/theme discovery and registries, lifecycle contracts, common HTTP/CLI integration, event contracts and rendering integration.
- **Core modules:** essential CMS features supplied by this project, such as system administration, accounts, access control, content modeling, field types and media. Being bundled with the project does not mean their business logic belongs in the kernel.
- **Contributed modules/themes:** external extensions installed through supported mechanisms.
- **Custom modules/themes:** installation-specific code.
- **Application/installation configuration:** selected modules/themes, site settings, content type definitions, field configuration, menus and similar installation data.

A module may own controllers, routes, services, configuration, entities, repositories, commands, event subscribers, migrations, templates, assets, permissions and tests. None of these folders is mandatory for a module that does not need them.

A theme owns presentation, template overrides, layouts, regions, component templates, assets and appearance settings. A theme **must not** implement business logic, authorization, database queries or entity persistence.

Do not put domain-specific concepts such as Blog, Product, Order or Campaign in the kernel. Model them in modules or in configuration-defined content types.

## 3. Technical baseline

- **Language:** PHP 8.4+ as the initial minimum target; check actual installed runtimes and package constraints before adopting newer language features. Prefer compatibility with PHP 8.5 where dependencies permit.
- **Dependencies:** Composer with PSR-4 autoloading. Pin or constrain packages appropriately and commit `composer.lock` for the application repository.
- **Templating:** Twig is mandatory for HTML templates. Use template autoescaping and explicit safe-output rules.
- **Persistence:** Doctrine DBAL is the default database abstraction; Doctrine ORM may be used for stable, code-defined entities. Neither should leak into independent domain contracts.
- **Databases:** MySQL/MariaDB are the default local/production reference. Design SQL and migrations with portability in mind; test at least MySQL and SQLite where practical. PostgreSQL compatibility is a desired objective, not an untested guarantee.
- **Tests:** PHPUnit.
- **Static analysis:** PHPStan.
- **Formatting:** PHP-CS-Fixer, based on PSR-12 and repository configuration.
- **CI:** GitHub Actions or the CI already configured in the repository.

Use an established library before reimplementing general infrastructure. Prefer small, explicit integrations over adopting a full framework accidentally. Record major library choices and tradeoffs in an ADR.

Do not assume Docker, a container name, an operating system, a database host, a web server, local credentials or an existing executable. Inspect the repository before invoking commands. Docker may be provided as an optional development environment.

## 4. Target repository organization

Treat this as a **target layout**, not a demand to create every directory immediately:

```text
/
├── core/
│   └── src/
│       ├── Bootstrap/
│       ├── Container/
│       ├── Config/
│       ├── Module/
│       ├── Theme/
│       ├── Routing/
│       ├── Http/
│       ├── Event/
│       ├── Render/
│       └── Contract/
├── modules/
│   ├── core/
│   ├── contrib/
│   └── custom/
├── themes/
│   ├── core/
│   ├── contrib/
│   └── custom/
├── config/
├── public/
│   └── index.php
├── bin/
│   └── console
├── storage/
│   ├── cache/
│   ├── logs/
│   └── files/
├── tests/
├── docs/
│   └── decisions/
├── composer.json
├── composer.lock
└── AGENTS.md
```

Use `public/` as the HTTP document root. Protect `config/`, source code, `vendor/`, private files and secrets from direct HTTP access. Only public assets intended to be served should live under the document root.

Keep runtime, generated and user-uploaded files out of version control, except intentionally tracked scaffolding such as `.gitkeep`. Use platform-independent paths, not hardcoded local absolute paths.

## 5. Module system — mandatory architectural rules

A module is the unit of functionality. Its manifest is `module.yaml` (subject to an explicit ADR if format changes).

Suggested structure:

```text
modules/custom/blog/
├── module.yaml
├── config/
│   ├── routes.yaml
│   ├── services.yaml
│   ├── permissions.yaml
│   └── settings.yaml
├── src/
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   ├── Command/
│   ├── EventSubscriber/
│   └── Plugin/
├── templates/
├── assets/
├── migrations/
└── tests/
```

Suggested minimal `module.yaml` example:

```yaml
id: blog
name: Blog
description: Blog content functionality
version: 0.1.0
requires: []
```

The module system must eventually support:

1. Discover modules from known roots; validate unique machine-readable IDs and manifests.
2. Register a module independently of enabling it.
3. Resolve dependencies, detect missing dependencies and circular dependencies.
4. Enable/disable modules with well-defined behavior. Disabling must not silently delete data.
5. Install/uninstall using explicit, safe lifecycle hooks. Destructive uninstall operations require clear confirmation/intent.
6. Register module services, routes, controllers, commands, permissions, event listeners, entity types and templates.
7. Support module-owned schema migrations, executed in a defined order.
8. Expose configuration defaults and installation-specific overrides.
9. Allow extension through interfaces, events and explicit plugin registries rather than monkey-patching or editing another module.
10. Make discovery/compiled registries cacheable and invalidate them predictably when modules change.

Module dependencies must be declared, and circular module dependencies are forbidden. Prefer communication through public contracts and events. Core must not depend on contributed or custom modules.

Do not execute arbitrary PHP files while scanning modules. Use validated metadata, Composer autoloading and explicit registration contracts. Do not treat installing third-party modules as a safe operation without considering code execution and trust.

The module loader must not depend on the presence of an HTTP request: CLI commands and tests need the same registration pipeline.

## 6. Theme system and Twig

Themes are discoverable, selectable and configurable independently of modules. Use `theme.yaml` as the theme manifest.

Suggested theme layout:

```text
themes/custom/mytheme/
├── theme.yaml
├── config/
│   └── settings.yaml
├── templates/
│   ├── layouts/
│   ├── pages/
│   ├── content/
│   ├── blocks/
│   └── components/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
└── screenshot.png
```

Required design goals:

- Distinct active **public** and **administration** themes.
- Deterministic template lookup and documented override precedence.
- A module may provide default Twig templates; the active theme may override them without changing module source.
- Support base-theme inheritance when implemented; prevent circular inheritance.
- Theme regions (for example, header, content, sidebar, footer) and configurable block placement.
- Reusable Twig components/layouts and content view modes (for example, full, teaser, card).
- Asset declaration and deduplication; no uncontrolled inclusion of CSS/JS from templates.
- Proper escaping, URL generation and asset URLs through framework services/extensions.

Define a single template namespace and override strategy; test its lookup order and failure behavior. Keep layout selection separate from controller logic. Never give templates arbitrary access to the entire service container.

## 7. HTTP, routing, controllers and rendering

The public entry point receives a request, boots the application, loads enabled modules and configuration, resolves a route, checks access, executes a controller and returns a response. Reuse mature standalone components where appropriate.

- Controllers are thin: validate/normalize requests, invoke application services and return responses or render descriptions.
- Route declarations belong to the module that owns them, with explicit names, methods, path requirements and access rules.
- Support clearly separated public/admin route groups or conventions without hardcoding business logic in the router.
- URL generation must use route names or entity URL resolvers, not literal URL concatenation.
- Apply access checks server-side even when navigation hides restricted links.
- Render through a central renderer using Twig, theme selection, template namespaces and view modes.
- Normalize errors to appropriate HTTP responses; never expose stack traces or sensitive internals in production.

### Friendly URLs / Pathauto

**Route matching and URL aliases are separate systems.** An alias is a public-facing path pointing to a canonical route/entity, not a new controller route.

Design extension points for token-based alias patterns (such as `/blog/{content:title}`), slug normalization, unique collision handling, language-aware aliases where applicable, and optionally redirects when aliases change. Do not make the full Pathauto/redirect feature a kernel prerequisite.

## 8. Entities, content types and fields

Keep these concepts explicit:

- **Entity type:** definition of a class/category of entity (for example, User, Content, TaxonomyTerm).
- **Bundle/content type:** administrator-configurable variation of a content entity (for example, Article or Page).
- **Entity instance:** an individual persisted record.
- **Field definition:** field type and schema-level configuration.
- **Field instance/configuration:** how a field is attached and configured for a content type.
- **Field widget:** editing UI.
- **Field formatter:** display rendering.
- **View mode:** field/display configuration for a particular presentation context.
- **Configuration entity:** structured system configuration rather than publishable site content.

Do not equate a dynamic content type with a manually written Doctrine ORM class.

### Persistence strategy

- Use Doctrine DBAL as the baseline for schema introspection, query execution, transactions and database-platform capabilities.
- Doctrine ORM is suitable for stable, code-defined entities where it helps.
- Dynamically defined content types/fields need an explicit metadata-driven storage subsystem, independent of generated PHP source files.
- Put storage behind interfaces; do not pass Doctrine connections/entities through unrelated module layers.
- Do not hardcode MySQL-only column definitions or SQL dialect in generic code. Isolate unavoidable platform-specific behavior in adapters.
- Before implementing dynamic field persistence, write an ADR comparing per-content-type tables, shared field-value tables and a hybrid strategy (including cardinality, references, indexing, filtering, migrations and revisions). **Choose and implement one minimal, testable strategy; do not build three.**
- Content schema/configuration changes must be validated and translated into safe, auditable migration operations. Never silently drop a field or its stored values.

### Field API

A field type should declare its supported value structure, validation rules, storage integration, form widget and display formatter through explicit contracts/registries.

Initial field types, introduced incrementally: string, text, integer, decimal, boolean, date/datetime, email, URL, list, entity reference, file/image and rich text. Support defaults, required/optional values, cardinality, constraints, and display settings. Add localization and revisions without corrupting the underlying model.

Form generation and display generation must derive from field metadata, with overridable widgets/formatters. Validation must run on the server, not only in browser forms.

## 9. Administration and CMS features

The administration should be easy to navigate like WordPress while offering Drupal-like configurability. The admin UI is a **module using an admin theme**, not a special fork of the kernel.

Planned areas:

- Dashboard and system status.
- Content listings, filters, create/edit/delete and publishing operations.
- Structure: content types, fields, menus, blocks and eventually configurable views.
- Appearance: theme selection, theme settings, regions and block placement.
- Extend: installed modules, dependencies, activation and diagnostics.
- Users: accounts, roles, permissions and profile management.
- Media and file management.
- Configuration, logs, maintenance and reports.

For MVP, implement working vertical slices before rich UI: a small authenticated admin screen, one entity/content type, basic field editing, list/edit forms, access checks and theme rendering. Never build a complex visual editor before the underlying API and persistence are tested.

## 10. Authentication, authorization and security

Use explicit authentication and authorization contracts. Role-based access control (RBAC) is a core CMS capability, with granular permission names registered by modules (for example, `content.article.create`, `content.article.edit_own`, `users.manage`).

- Enforce authorization at protected routes **and** business operations.
- Support owner-aware access checks rather than only global roles.
- Apply secure password hashing, session handling, login throttling/rate limiting where applicable and session renewal on authentication.
- Require CSRF protection for state-changing browser forms; do not use GET for destructive operations.
- Use Twig escaping by default and sanitize rich HTML using an established policy/library.
- Validate uploaded files, enforce allowed types and sizes, and store private files outside the public document root.
- Prefer prepared statements/DBAL parameters; do not concatenate untrusted SQL identifiers or values.
- Never expose secrets in logs, responses, generated docs or commits.
- Default to least privilege and deny access when authorization rules are missing or ambiguous.

Permissions, access-control decisions, security-critical events and module lifecycle changes must be testable.

## 11. Configuration and environments

Keep distinct:

1. **Module/theme code defaults:** versioned with the extension.
2. **Site configuration:** installed modules, roles, content type definitions, field settings, menus, block placements, etc.; should ultimately support export/import and versioning.
3. **Environment/secrets:** database credentials, encryption keys, deployment-specific settings; never export them as general site configuration.
4. **Content:** articles, users' content, media metadata and other editorial data; not part of configuration export.

Define precedence explicitly (defaults, site configuration, environment overrides). Validate configuration schemas, report unknown/invalid settings and invalidate relevant caches upon changes.

Avoid making the database a mandatory dependency of the earliest kernel/bootstrap tests. A CLI command such as `list` or `help` should be able to report meaningful information without installing an entire site where reasonable.

## 12. Quality, standards and source conventions

- Prefer PSR-4, PSR-12, SOLID, KISS and YAGNI; apply DDD/hexagonal separation **pragmatically** where actual domain complexity justifies it.
- Use `declare(strict_types=1);` in PHP source files.
- Type arguments, return values and properties. Prefer constructor injection, composition, immutable values and `readonly` when appropriate.
- Use native attributes or explicit configuration consistently; avoid runtime magic and fragile naming conventions.
- Keep controllers, commands and event subscribers thin. Put domain/application behavior in well-named classes.
- Do not use global mutable state, service locators inside domain code or static helpers as substitutes for dependency injection.
- Avoid generic `Utils/`, `Helpers/` and `Service/` dumping grounds when a more precise responsibility exists.
- Keep each module's public API small and documented. Do not access another module's internal implementation classes directly.
- All source identifiers, comments, PHPDoc, tests, migrations and Git commits must be in **English**. User-facing strings must be translatable rather than hardcoded into domain code.
- Comments explain **why**, not the obvious meaning of a line. Do not add noisy boilerplate PHPDoc to fully typed code.
- Avoid speculative abstractions, interfaces with only hypothetical use cases, and premature microservices.

Never modify installed dependencies in `vendor/`, generated cache, compiled assets or runtime files as a way to fix source behavior.

## 13. Tests and acceptance checks

Use layered tests:

- **Unit:** configuration normalization, module dependency graph, permissions, slug creation, field constraints, domain behavior.
- **Integration:** container/module registration, route registration, template override order, DBAL persistence, migrations, lifecycle hooks.
- **Functional:** full HTTP request-to-response behavior, login/access restrictions, admin forms, content CRUD, alias resolution.

Use isolated fixtures. Tests must be deterministic, run without relying on external network services, and clean up their state. Prefer SQLite for fast database-independent tests but also include MySQL integration checks for supported schemas and query behavior. Do not claim cross-database support on the basis of SQLite tests alone.

Every meaningful task must include or update relevant tests. Run available repository checks after changes (for example, Composer validation, PHPUnit, PHPStan, PHP-CS-Fixer and builds) and report exactly what ran, passed, failed or could not run. **Never invent test results.**

For a new extension point, include a small example or fixture that demonstrates a third-party module/theme can use it without modifying core code.

## 14. CLI and developer experience

Offer a consistent CLI entry point such as `php bin/console` when the implementation reaches that stage. Useful planned commands include:

```text
module:list
module:enable <id>
module:disable <id>
module:install <id>
module:uninstall <id>
theme:list
cache:clear
config:export
config:import
migrate
```

These are **planned names, not existing commands**. Do not advertise them as available before implementation. Provide clear exit codes and useful error messages. Avoid requiring interactive confirmations in noninteractive CI; destructive commands need safe explicit flags or equivalent controls.

A new developer should eventually be able to install dependencies, configure an environment, run migrations, start the application, execute tests and create a first custom module using documented steps. Maintain a working minimal example instead of only architectural scaffolding.

## 15. Delivery plan — implement in vertical slices

**Phase 1 — Standalone runtime and module loading**

- Composer project, PSR-4 autoloading, bootstrap and HTTP/CLI entry points.
- Configuration loader and dependency container.
- Module discovery/manifest validation, registration and dependency ordering.
- Simple route/controller/response flow through an example module.
- Core tests and basic README with reproducible startup instructions.

**Acceptance:** an HTTP request and a CLI command bootstrap the same application; one custom module registers a route and service without editing kernel source.

**Phase 2 — Twig and themes**

- Twig renderer, theme discovery/selection and module fallback templates.
- Theme overrides, a basic layout and region interface.
- Separate admin/public theme selection foundations.

**Acceptance:** two themes can render the same module response differently, and an override works by documented precedence.

**Phase 3 — Database and minimal administration**

- DBAL connection configuration and migration infrastructure.
- Authentication, users, roles, permissions and CSRF protection.
- Minimal administration module and Twig administrative theme.

**Acceptance:** an administrator can authenticate, view a protected page and manage a minimal protected resource; unauthorized users cannot.

**Phase 4 — Configurable content**

- Entity/metadata contracts and the selected dynamic-field storage design.
- One content type with a small field set, server-side validation and CRUD.
- Generated admin forms and themed public rendering.
- First alias-pattern implementation.

**Acceptance:** an administrator creates/configures a content type, adds fields, creates content and visits it through a friendly URL without writing a custom controller for that type.

**Later phases (not MVP prerequisites):** advanced Views-like querying/UI, taxonomy, media processing, workflow, revisions, translations, configurable blocks/menus, external API, search indexing, scheduled tasks and extension marketplace.

Each phase should leave a working, tested application. Do not scaffold all future modules in advance. Prioritize the next smallest end-to-end capability.

## 16. Codex execution protocol

When given a development task:

1. **Inspect** the repository, Composer dependencies, entry points, existing tests and relevant documentation first.
2. **Restate the implementation target internally** and identify the smallest affected layers/modules. Do not silently expand scope.
3. **Respect ownership:** change the responsible module/theme rather than adding CMS functionality to core.
4. **Choose established components** when appropriate, but evaluate dependency impact and compatibility. Document consequential decisions.
5. **Implement a complete vertical slice** with error handling and tests rather than broad placeholder scaffolding.
6. **Run the available checks** and inspect the diff for accidental changes, secrets, missing migration steps and backwards incompatibilities.
7. **Report** a concise summary of modified files, architectural decisions, executed checks, known limitations and reasonable next step.

Before an irreversible decision involving storage, public API, configuration format or extension lifecycle, add a brief ADR covering context, considered options, the chosen approach and consequences. Do not block routine local implementation on unnecessary paperwork.

Do not:

- Treat this project as a Symfony skeleton app or install FrameworkBundle by default.
- Recreate Symfony, Twig or Doctrine features without a concrete reason.
- Introduce Laravel, Drupal or WordPress as a runtime dependency.
- Write an entire CMS in one change or bypass test coverage for a large scaffold.
- Add application-specific business entities to the kernel.
- Couple themes to SQL or bypass authorization in admin endpoints.
- Invent existing classes, tools, configuration keys, commands or test results.
- Commit credentials, `.env.local`, private keys, caches, uploaded files or generated vendor artifacts.
- Run destructive database commands without explicit task authorization.

## 17. Git, documentation and change hygiene

Use Conventional Commits in English, for example:

```text
feat(core): add module manifest discovery
feat(theme): support Twig template overrides
feat(content): register configurable field types
fix(security): enforce entity edit permissions
test(module): cover circular dependency detection
docs(architecture): document dynamic field storage decision
```

Keep pull requests/commits focused. Do not force-push or rewrite unrelated history without explicit instruction. Preserve user changes and avoid reverting files not owned by the current task.

Maintain:

- `README.md` for installation, development and supported functionality.
- `docs/architecture.md` for module/theme/kernel boundaries and extension points.
- `docs/decisions/` for consequential architectural choices.
- `docs/modules.md` and `docs/themes.md` once their public developer APIs exist.
- A running feature/status checklist distinguishing implemented, partial and planned features.

**Definition of done:** the requested capability works in an end-to-end path, follows the module/theme boundaries, has appropriate tests, passes available checks or documents blockers, and is accurately described without overstating completeness.
