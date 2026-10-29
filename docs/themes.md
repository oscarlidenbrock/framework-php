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

Set `active_theme` and `admin_theme` in `config/app.yaml`. Both are validated at boot. Public rendering uses `active_theme`; administration pages use a separate TemplateManager with `admin_theme` and its own global libraries. The bundled `admin_theme` provides a sidebar layout and dashboard. Enabled modules add navigation through `config/admin_navigation.yaml`, naming a route and required permission. Run `APP_WORKSPACE=development php bin/console theme:publish-assets` after installing or changing the theme so its CSS is served from `public/assets/`; runtime requests never publish source files. The `default` theme supplies the public layout. The `contrast` child theme demonstrates a template override: select it and visit `/example`.

## Template lookup

| Template request | Lookup order |
| --- | --- |
| `@theme/layouts/base.html.twig` | Active theme `templates/`, then each parent `templates/` |
| `@example/index.html.twig` | Active theme `templates/modules/example/`, then each parent override directory, then the enabled module's `templates/` |

The first existing file wins. Disabled modules register no Twig paths. The module ID `theme` is reserved for Twig. Missing templates raise Twig's `LoaderError`; HTTP failures become 500 responses without stack traces.

Controllers can inject `Framework\Template\TemplateManager` and return `renderResponse('@example/index.html.twig', ['libraries' => ['example/base']], ['title' => 'Example'])`. Direct `Response` controllers remain valid. Twig uses HTML autoescaping and strict variables; templates receive view data, not the service container. The default layout exposes `stylesheets`, `head_scripts` and `scripts` blocks, calling `assets_css()` and `assets_js('head'|'body')`. Assets resolve before Twig renders `<head>`.

Set `twig_cache: false` for development; production can enable compiled templates under `storage/cache/twig/` with auto reload disabled. Clear that directory after template changes. `theme_discovery_cache` stores validated theme manifests under `storage/cache/` and checks root, directory and manifest metadata on later boots. Library definitions are read on bootstrap, so new processes see changed `config/libraries.yaml` without a separate library cache.
