# ADR 0002: Twig namespaces, theme inheritance and assets

Status: Accepted

The runtime cache path in this decision is superseded by [ADR 0019](0019-workspace-runtime-and-logs.md).

## Context

Module templates need a deterministic theme override path without coupling PHP class autoloading to presentation. Active theme selection and asset publication become extension and deployment contracts.

## Options considered

- Custom template names with a framework parser. This duplicates Twig namespaces and makes ordinary Twig tooling less useful.
- Copy module templates into themes. This loses an automatic fallback and requires editing theme files for every module update.
- Use Twig's `FilesystemLoader` namespaces with ordered search paths for active themes, parents and module defaults.

## Decision

Use the third option. `@theme` searches the active public theme and its parent chain. `@{module_id}` searches override directories in that chain before the enabled module's templates. `theme` is reserved as a module ID. `theme.yaml` declares identity, PHP namespace, optional parent and global library IDs. The complete inheritance graph is validated at discovery; PHP namespaces use Composer's `addPsr4()` independently of Twig. Public and administration theme IDs are stored separately, though only public rendering exists now. Twig uses autoescaping and strict variables. Its compiled cache and optional theme discovery cache live under `storage/cache/`.

Theme CSS and JS are declared in named libraries and copied by `theme:publish-assets` to `public/assets/themes/`; theme source files remain outside the document root. Controllers inject `TemplateManager` and may return its HTML `Response`, while existing direct `Response` controllers remain valid. The mandatory library design is specified in ADR 0005.

## Consequences

Removing an override restores the next available template on a new bootstrap. Twig's own loader handles lookup; no custom parser or SPL autoloader is introduced. Production templates need cache clearing after changes because auto reload is disabled. Asset publication is explicit and generated public files need deployment cleanup when declarations change. Administration rendering, blocks, regions and advanced asset processing remain future work.
