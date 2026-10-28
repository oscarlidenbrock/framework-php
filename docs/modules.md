# Module development

Create a directory directly under `modules/core/`, `modules/contrib/`, or `modules/custom/`. The category affects discovery only; it does not change the PHP namespace. For example, a custom `hello` module can contain:

```text
modules/custom/hello/
├── module.yaml
├── config/
│   ├── services.yaml
│   └── routes.yaml
└── src/
    └── Controller/
        └── HelloController.php
```

`module.yaml`:

```yaml
id: hello
name: Hello
namespace: Hello
version: 0.1.0
requires: []
```

The ID is lowercase letters, digits and underscores, beginning with a letter. `namespace` is a case-sensitive PHP segment beginning with an uppercase letter. IDs and namespace segments must be unique across all categories. `requires` lists module IDs that must also be enabled. Missing or circular enabled dependencies fail boot. Discovery validates manifests without loading module PHP classes.

The framework registers `Module\Hello\` for the module's `src/` directory through Composer before loading service definitions. It also registers namespaces for disabled modules, while their services and routes remain inactive.

`config/services.yaml` uses Symfony DependencyInjection's YAML service format. Controllers must be public services so the HTTP kernel can retrieve them:

```yaml
services:
  hello.controller:
    class: Module\Hello\Controller\HelloController
    public: true
```

`config/routes.yaml` maps route names to paths, HTTP methods and controller service IDs:

```yaml
hello.index:
  path: /hello
  methods: [GET]
  controller: hello.controller
```

The optional `requirements` mapping can constrain path parameters with regular expressions. Unknown route keys fail boot, including an `access` key: authorization is not implemented in this phase. The controller is an invokable service that accepts a `Symfony\Component\HttpFoundation\Request` and returns a `Symfony\Component\HttpFoundation\Response`. Route names must be unique. Routes and services are loaded only for enabled modules, before the container is compiled. Module dependencies are loaded first.

Enable the module by adding `hello` to `enabled_modules` in `config/app.yaml`, then check it with `php bin/console module:list`. Remove it from that list to disable its routes and services without deleting module code or data. The [example module](../modules/custom/example/) is a working reference.

This phase supports public routes only. Authorization and module lifecycle commands are later work; do not use current routes for protected operations.
