# ADR 0019: Workspace runtime files and first log index

Status: accepted

## Context

Generated caches currently mix workspaces under `storage/cache`, while installation snapshots determine bootstrap selection. Administration needs bounded, permission-controlled access to recent diagnostics.

## Decision

Runtime files live below `var/cache/{workspace}/` and `var/logs/{workspace}/`; public assets remain under `public/assets/`. A validated workspace name is part of every runtime path. Bootstrap and recovery use only the new installation snapshot path. Existing snapshots require an explicit one-time copy to the matching workspace directory before deployment, followed by `installation:refresh`; the framework does not read or delete the old path. Discovery and compiled caches can be regenerated under the new layout. Tool caches use the reserved `_tools` directory, whose name is not a valid site workspace.

Use the installed `psr/log` contract with a small file logger because Monolog is not installed and adding a package would require a network-dependent lockfile change. JSON lines hold UTC time, channel, level, message and sanitized structured context. A lock file coordinates append and size-based rotation across workers. Retention and maximum size are configuration values. A failed write is dropped without invoking the logger recursively.

The first administration view reads recent lines directly from bounded tails of files and opens one bounded line for detail. No log write or read connects to the database. Secrets are excluded at call sites and sensitive context keys are redacted by the logger.

## File reading versus a database index

Reading bounded file tails needs no migration, remains available when the database is down, and is sufficient for recent diagnostics. It cannot provide complete historical search or efficient pagination across a large archive. An auxiliary database index could support those queries, but would add schema, synchronization, backup and recovery obligations, including reconciling rotated files. Implement bounded file reading now; revisit an index if archive search becomes a product requirement.

## Consequences

Deployments must grant the PHP/CLI user write access to `var/cache` and `var/logs`. Copy and verify any existing snapshot before deploying the path change. Old caches may be removed after deployment and selection verification. Logs are local to each workspace and rotate by channel.
