# Framework

A standalone PHP modular framework. The runtime discovers modules and themes, builds a service container, matches HTTP routes, renders HTML with Twig, and offers optional multi-connection Doctrine persistence. It does not yet provide authentication or an administration UI.

## Requirements and local run

- PHP 8.4.1 or newer
- Composer 2

```sh
composer install
APP_WORKSPACE=development php bin/console module:list
APP_WORKSPACE=development php bin/console module:publish-assets
APP_WORKSPACE=development php bin/console theme:list
APP_WORKSPACE=development php bin/console theme:publish-assets
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `http://127.0.0.1:8000/example`. The example module renders an HTML page using its own Twig template and the active theme's layout. The HTTP document root is `public/`.

The two `publish-assets` commands copy CSS/JS declared by named libraries from extension source directories outside `public/` into ignored public files. Run them after changing assets and during deployment.

For Apache, set `DocumentRoot` to `public/`, enable `mod_rewrite`, and allow `.htaccess` directives for that directory (for example, `AllowOverride All`). `public/.htaccess` sends paths that are not existing files or directories to `index.php`. The root path `/` still returns 404 until a module registers a route for it.

## Configuration and checks

`config/app.yaml` lists enabled module IDs and selects `active_theme` and `admin_theme`. The example module is enabled by default. Set `active_theme: contrast` to see the child theme override its page; set it back to `default` to use the module template. Disabling the module leaves it discoverable but makes `/example` return 404.

`config/workspaces.yaml` maps HTTP hostnames to workspace directories. The included `development` workspace accepts `localhost` and `127.0.0.1`. CLI commands require `APP_WORKSPACE` or `--workspace`. See [docs/workspaces.md](docs/workspaces.md) before adding deployment hostnames or overrides.

Persistence is optional. To configure it, copy `config/database.example.yaml` to ignored `config/database.yaml` and set the named environment variables outside the repository. See [docs/database.md](docs/database.md) for DBAL and ORM APIs, module mappings, and per-connection migration commands.

The optional `content` module adds dynamic scalar content types. Enable it in `enabled_modules` after configuring a database, apply its migration, then create types and synchronize field changes explicitly. See [docs/content.md](docs/content.md) for the Article example, CLI commands, storage API and current limits.

`module_discovery_cache` and `theme_discovery_cache` store validated discovery data in `storage/cache/`. `twig_cache: true` stores compiled Twig templates there. Use these settings in production; remove the corresponding cache directories after deployments that preserve timestamps or change templates while auto reload is disabled.

```sh
vendor/bin/phpunit
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --sequential
composer validate
```

See [docs/modules.md](docs/modules.md) for module services and routes, [docs/themes.md](docs/themes.md) for themes and Twig overrides, [docs/assets.md](docs/assets.md) for mandatory asset libraries, [docs/workspaces.md](docs/workspaces.md) for configuration inheritance, and [docs/architecture.md](docs/architecture.md) for feature status.
