# Workspaces and inherited configuration

`config/workspaces.yaml` maps exact hostnames to workspace names:

```yaml
localhost: development
dev.example.com: development
www.example.com: production
```

Names use lowercase letters, digits and underscores and start with a letter. Create a matching `config/workspaces/{name}/` directory for every workspace, even if it has no overrides. There are no wildcard or regular-expression host rules. Hostnames are compared case-insensitively without ports. An unknown host, invalid name, missing directory or hostname/workspace mismatch stops bootstrap. The public entry point returns a generic error without exposing configuration values. HttpFoundation parses and validates the request hostname; the resolver then checks the exact configured host list. Proxy forwarding headers are not trusted unless the server explicitly configures trusted proxies. Configure only deployment-owned hostnames.

The root `config/*.yaml` files provide shared values. The selected workspace may override any file with the same relative name below `config/workspaces/{name}/`; it may also add a file. `workspaces.yaml` itself is never overridden. The loader reads the root and the selected workspace only. Files must contain YAML mappings; malformed or unreadable files fail with a filename in the error.

```text
config/app.yaml
config/database.yaml
config/workspaces/production/app.yaml
config/workspaces/production/database.yaml
```

The merge copies values from the base document and applies workspace keys recursively when both values are nonempty maps. Missing keys remain unchanged. Scalars, `null`, `false`, `0`, empty strings and changed types replace the old value. Lists replace whole lists, including an explicit empty list. An explicit empty map also clears the old value; both `{}` and `[]` become an empty PHP array after YAML parsing. Neither input is mutated.

`$core->config()->get('app.debug')` reads an effective value. `$core->config()->get('database')` returns the complete effective database section; a missing key returns `null`. `ConfigManager::load()` still returns validated application settings. `config/modules.yaml` may hold `enabled_modules`; when present, that list takes precedence over `app.yaml` as the initial seed. After installation selection is persisted, Bootstrap uses the workspace-specific snapshot for module/theme choices. Other workspace YAML overrides are applied before registration, Twig setup, Doctrine registration and container compilation. See [installation-selection.md](installation-selection.md).

For HTTP, the request hostname chooses the workspace. `APP_WORKSPACE` may lock a deployment to one workspace; a request for a hostname mapped to another is rejected. HTTP query parameters and arbitrary headers never select the workspace. The process must configure trusted reverse proxies explicitly if it wants HttpFoundation to use forwarded host headers.

For CLI commands, provide `APP_WORKSPACE=development` or `--workspace=development` before or after the command name. The option takes precedence over the environment variable. Commands that boot the application fail clearly if neither is set. `help` does not boot and needs no workspace.

```sh
APP_WORKSPACE=development php bin/console module:list
php bin/console --workspace=production database:status
```

`config/database.yaml` is merged before Doctrine sees it, so workspaces can override one connection property without copying all connections. Existing `url_env` values are resolved only on first connection access. Keep URLs and passwords in environment variables or untracked deployment configuration. DBAL parameters may be written directly under a connection or inside `params`; nested `params` wins on duplicate keys. See [database.md](database.md).

Effective YAML configuration is held in memory for one booted Core. The installation selection snapshot is a separate workspace-specific cache of database-owned module/theme choices. Module and theme discovery caches contain installation metadata independent of workspace selection. Twig compiled templates and Doctrine metadata caches use workspace-specific namespaces; containers are built separately for each Core. A persistent worker must create a new Core when its workspace changes, and restart or rebuild it after editing configuration or installation selection. One booted Core rejects a request mapped to another workspace.
