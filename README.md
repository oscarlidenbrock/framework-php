# Framework

A standalone PHP modular framework in its first functional phase. The current runtime discovers modules, enables selected modules, builds a service container, matches HTTP routes, and invokes controllers. It does not yet provide themes, persistence, authentication, or an administration UI.

## Requirements and local run

- PHP 8.4 or newer
- Composer 2

```sh
composer install
php bin/console module:list
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `http://127.0.0.1:8000/example`. The response is `Example module is running`. The HTTP document root is `public/`.

For Apache, set `DocumentRoot` to `public/`, enable `mod_rewrite`, and allow `.htaccess` directives for that directory (for example, `AllowOverride All`). `public/.htaccess` sends paths that are not existing files or directories to `index.php`. The root path `/` still returns 404 until a module registers a route for it.

## Configuration and checks

`config/app.yaml` lists enabled module IDs. Removing `example` from `enabled_modules` leaves it discoverable but makes `/example` return 404. `module_discovery_cache: true` stores validated discovery data in `storage/cache/modules.json` and checks directory and manifest metadata on later boots. Delete that file after deployments that preserve timestamps.

```sh
vendor/bin/phpunit
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --sequential
composer validate
```

See [docs/modules.md](docs/modules.md) for the module manifest, services and routes format. The current feature status is recorded in [docs/architecture.md](docs/architecture.md).
