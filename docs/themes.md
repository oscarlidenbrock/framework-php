# Themes and Twig templates

Themes live under `themes/core/`, `themes/contrib/` or `themes/custom/`. Installation category does not affect names or template precedence. A manifest declares global library IDs, not files:

```yaml
id: mytheme
name: My Theme
namespace: MyTheme
version: 0.1.0
parent: default
libraries:
  - mytheme/global
```

Define `mytheme/global` in `config/libraries.yaml` inside the theme. Its CSS and JavaScript files remain in the theme's `assets/` directory. Run `php bin/console theme:publish-assets` to copy declared files to ignored paths under `public/assets/themes/{theme_id}/`. See [assets.md](assets.md) for syntax, attributes, dependencies, validation and rendering. A parent theme's global libraries attach before the child's; explicit dependencies are then resolved. A theme with no global assets may use `libraries: []`.

IDs are lowercase letters, digits and underscores beginning with a letter. Namespace segments begin with an uppercase letter and must be unique regardless of case. `parent` is another theme ID or `null`. Missing parents, cycles, duplicate IDs and duplicate namespaces fail discovery. PHP classes, if needed, live in `src/` under `Theme\MyTheme\`; Composer registers that prefix during bootstrap.

`active_theme` and `admin_theme` in `config/app.yaml` seed a new installation. The `/admin/themes` page or `theme:select public|admin <id>` command persists later workspace selections. Both are validated at boot. Public rendering uses `active_theme`; administration pages use a separate TemplateManager with `admin_theme` and its own global libraries. The bundled `admin_theme` provides a sidebar layout and dashboard. Enabled modules add navigation through `config/admin_navigation.yaml`, naming a route and required permission. Run `APP_WORKSPACE=development php bin/console theme:publish-assets` after installing or changing the theme so its CSS is served from `public/assets/`; runtime requests never publish source files. If an administration theme breaks access, use `installation:recover` as described in [installation-selection.md](installation-selection.md). The `default` theme supplies the public layout. The `contrast` child theme demonstrates a template override: select it and visit `/example`.

## Template lookup

| Template request | Lookup order |
| --- | --- |
| `@theme/layouts/base.html.twig` | Active theme `templates/`, then each parent `templates/` |
| `@example/index.html.twig` | Active theme `templates/modules/example/`, then each parent override directory, then the enabled module's `templates/` |

The first existing file wins. Disabled modules register no Twig paths. The module ID `theme` is reserved for Twig. Missing templates raise Twig's `LoaderError`; HTTP failures become 500 responses without stack traces.

Controllers can inject `Framework\Template\TemplateManager` and return `renderResponse('@example/index.html.twig', ['libraries' => ['example/base']], ['title' => 'Example'])`. Direct `Response` controllers remain valid. Twig uses HTML autoescaping and strict variables; templates receive view data, not the service container. The default layout exposes `stylesheets`, `head_scripts` and `scripts` blocks, calling `assets(type, slot)` for CSS, JavaScript and fonts. Assets resolve before Twig renders `<head>`.

Set `twig_cache: false` for development; production can enable compiled templates under `storage/cache/twig/` with auto reload disabled. Clear that directory after template changes. `theme_discovery_cache` stores validated theme manifests under `storage/cache/` and checks root, directory and manifest metadata on later boots. Library definitions are read on bootstrap, so new processes see changed `config/libraries.yaml` without a separate library cache.

## Regions and blocks

Declare region IDs in `theme.yaml` under `regions`, for example `page: {}` and `sidebar: {parent: page}`. Child themes inherit region definitions and may override them by ID. A region template at `templates/regions/{id}.html.twig` receives `content` (rendered blocks) and variables named for its immediate subregions. Normal theme template override precedence applies. Without a region template, block and child-region output is concatenated. Layouts receive top-level region variables such as `page` and `sidebar` as safe renderer-produced HTML. Block variables still escape in their Twig templates.

Enabled modules register `Framework\Block\BlockInterface` services with the `framework.block` tag. IDs use `module_id.block_id`. The administration page at `/admin/appearance/blocks` saves public-theme placements, including enabled state and integer weight. Placements are keyed by workspace and theme in the `config` table. Administration theme placements use a separate theme key. The public layout renders `page` and `sidebar` inside `{% block content %}`. Existing page templates that override `content` should call `{{ parent() }}` once to display placed blocks alongside their existing content; the bundled templates do this. A new region-driven page can rely on the base block directly. Do not print the same region separately in the override.
