# ADR 0007: Host-selected workspaces with one effective configuration manager

Status: accepted

## Context

The application needs shared YAML configuration with deployment-specific overrides before modules, themes, Doctrine and services are initialized. Separate configuration readers would allow different subsystems to boot from different environments. HTTP and CLI have different selection inputs, and an incorrect default could expose a production service in development or vice versa.

## Options considered

- Copy full configuration trees per environment: simple lookup but duplicates settings and encourages drift.
- Let each subsystem read its own workspace files: small local changes but inconsistent merges and selection.
- Resolve one workspace before boot and merge files in ConfigManager: one effective source for all consumers.

## Decision

Use exact hostname mappings in special `config/workspaces.yaml`. HTTP uses HttpFoundation host validation; CLI requires `APP_WORKSPACE` or `--workspace`, with the option taking precedence. An environment-selected workspace constrains HTTP host selection. `ConfigManager` merges root and selected workspace YAML files by relative path, recursively merging maps and replacing lists, empty arrays and other values. Bootstrap passes its effective database document to `DatabaseRegistry` and its validated app/module settings to other managers before container construction. A Core cannot serve another workspace after boot.

## Consequences

Configuration and compiled services stay isolated by Core and workspace. Twig and Doctrine caches include workspace names; discovery caches remain shared because their metadata does not depend on configuration. There is no persistent effective-configuration cache yet, avoiding invalidation complexity. Persistent processes must rebuild Core after configuration changes. Unknown hosts and missing CLI selection fail rather than falling back to an environment.
