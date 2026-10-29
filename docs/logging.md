# Runtime files and logging

The project root contains `var/cache/{workspace}/` for generated caches and the installation selection snapshot, and `var/logs/{workspace}/` for private logs. `public/assets/` remains public. Future private temporary files belong under `var/tmp/{workspace}/`; private uploads belong under `var/files/{workspace}/`. These directories are ignored by Git and must not be served by the web server. The PHP worker and CLI deployment user need write access to their workspace's cache and log directories (and read access to their own logs). Do not grant one workspace process access to another workspace's runtime directory when deployments use distinct users.

## Updating an existing installation

1. Before deploying the new code, create writable `var/cache/{workspace}` and `var/logs/{workspace}` for each configured workspace.
2. If an existing installation has a selection snapshot, copy its validated contents from `storage/cache/installation/{workspace}.json` to `var/cache/{workspace}/installation.json` for the same workspace and verify the copy. Do this before serving requests with the new code. Bootstrap reads **only** the new path; without a snapshot it uses YAML seed selection. A corrupt new snapshot fails boot.
3. Run `php bin/console --workspace={workspace} installation:refresh` after deployment to validate the database rows and rewrite the new snapshot. Verify the active selection, then remove the old snapshot and obsolete generated caches. `installation:recover` removes only the new snapshot; use it solely for deliberate recovery to YAML seed settings.
4. Old module, theme, Twig, Doctrine, PHPUnit and PHP-CS-Fixer caches can be removed after deployment. They are regenerated under `var/cache/`. The framework does not read or manage the old locations.

PHPUnit and PHP-CS-Fixer use `var/cache/_tools/`. The leading underscore is excluded from valid site workspace names, so tool caches cannot overlap a workspace. Local configuration can set per-workspace `logging.yaml` overrides.

## Logging API

`Framework\Log\LoggerFactory` is injectable. Call `channel('module_id')` to obtain a PSR-3 logger; the default `Psr\Log\LoggerInterface` service uses the `application` channel. Each JSON line records UTC `time`, `channel`, `level`, `message` and structured `context`. Keep messages static and pass only safe metadata. Sensitive context keys such as `password`, `cookie`, `csrf`, `token`, `secret`, `credential`, `authorization` and `request_body` are redacted recursively. Never put secrets in the message itself.

`config/logging.yaml` sets `logging.max_bytes` (at least 4096) and `logging.retention` (1–30 rotated files) per workspace. Writers use a channel lock file to serialize append and rotation. An unwritable path drops that event without raising a second logging error. Monitor directory permissions because dropped events are not queued. The kernel logs unexpected errors by exception class, normal 404s are not logged, failed login records exclude identity and request data, and installation changes record validated IDs.

Administrators with `admin.logs.view` can inspect the 50 most recent records at `/admin/logs`, filter by channel or level, and open a detail record. Each read examines at most 32 files, 64 KiB per file and 200 recent lines per file; detail is limited to the 100 most recent entries. This is a recent-diagnostics view, not a complete searchable archive. See [ADR 0019](decisions/0019-workspace-runtime-and-logs.md) for the file-versus-database decision.
