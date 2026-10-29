# Module development

Create a directory under `modules/core/`, `modules/contrib/` or `modules/custom/`. A minimal module may contain `module.yaml`, a `src/` directory and optional `config/`, `templates/` and `assets/` directories. Its PHP namespace is `Module\{Namespace}\`, independent of installation category. Example manifest:

```yaml
id: hello
name: Hello
namespace: Hello
version: 0.1.0
requires: []
```

IDs are lowercase letters, digits and underscores beginning with a letter; PHP namespace segments begin with an uppercase letter. IDs and namespace segments must be unique. `requires` lists enabled module IDs. Missing or circular enabled dependencies fail boot. Discovery validates metadata without loading module PHP classes; Composer registers module namespaces before service definitions load.

For CSS or JavaScript, define named libraries in `config/libraries.yaml`:

```yaml
page:
  version: 1.0.0
  css: css/hello.css
  js:
    js/hello.js:
      defer: true
```

The files reside in the module's `assets/` directory. Run `php bin/console module:publish-assets` to copy declared files to `public/assets/modules/{module_id}/`. The copies are ignored by Git. See [assets.md](assets.md) for dependencies, all file properties, validation and publication rules.

`config/services.yaml` uses Symfony DependencyInjection's YAML format. Controllers must be public services:

```yaml
services:
  hello.controller:
    class: Module\Hello\Controller\HelloController
    arguments: ['@Framework\Template\TemplateManager']
    public: true
```

`config/routes.yaml` maps route names to paths, HTTP methods and controller service IDs:

```yaml
hello.index:
  path: /hello
  methods: [GET]
  controller: hello.controller
  access: public
```

Optional `requirements` constrain path parameters. Every route requires `access: public`, `access: authenticated` or `access: permission:<declared-id>`. Modules declare permission IDs in `config/permissions.yaml`; unknown IDs and malformed rules fail boot. Protected operations must repeat authorization in their services. See [security.md](security.md). Controllers are invokable services accepting a `Request` and returning a `Response`. Services and routes are loaded only for enabled modules before container compilation.

For HTML, inject `Framework\Template\TemplateManager` and call `renderResponse('@hello/index.html.twig', ['libraries' => ['hello/page']], ['title' => 'Hello'])`. The second argument attaches library IDs, the third supplies Twig variables, and an optional fourth sets status. Controllers or services may also inject `Framework\Asset\AssetManager` and call `attachLibrary()` before rendering. Direct CSS/JS lists are rejected.

Enable `hello` by adding it to `enabled_modules` in `config/app.yaml`; inspect with `php bin/console module:list`. Removing the ID disables its routes, services and libraries without deleting code or data. The [example module](../modules/custom/example/) is a working reference.

Field types can be registered by enabled modules as services tagged `framework.field_type`. They implement `Framework\Field\FieldTypeInterface` and are available to the content module before content types are used. See [content.md](content.md).

For attribute-mapped entities and Doctrine migrations, a module may use `src/Entity/` and `migrations/`, assigned to its configured EntityManager or the default connection. Declare additional mappings and migration groups in `module.yaml`. See [database.md](database.md) for the manifest format, named service IDs and migration commands.
