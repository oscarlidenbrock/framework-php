# ADR 0006: Independent Doctrine contexts per named database

Status: accepted

## Context

The framework needs optional persistence for independent module data stores, including mixed relational engines. A single global connection or EntityManager would couple unrelated modules and make migrations ambiguous. The existing bootstrap and DI container must remain usable without any database configuration.

## Options considered

- One default DBAL connection and EntityManager: simple, but cannot isolate multiple stores.
- One DBAL registry with shared ORM metadata and migration history: supports SQL access but risks mapping collisions and cross-database migration mistakes.
- Named lazy Doctrine contexts: one DBAL connection, optional ORM EntityManager and Doctrine Migrations configuration per name.

## Decision

Use named lazy contexts, owned by `DatabaseRegistry` and exposed through `DatabaseManager`. The optional `config/database.yaml` selects a default and declares each connection through an environment URL or DBAL parameters. Enabled module manifests assign attribute mapping directories and migration namespaces to connections. Bootstrap publishes explicit DI IDs and default DBAL/ORM aliases. Doctrine DBAL 4, ORM 3, Migrations 3 and Symfony Cache provide the implementation; no custom SQL driver or ORM is introduced. A CLI `--connection` selects one context for status, validation or migration. Migration execution requires `--yes`. HTTP processing clears initialized ORM identity maps after each request.

## Consequences

Contexts and migration histories remain separate. Database-free bootstrap still works. A module cannot map the same entity into multiple EntityManagers or declare ORM associations across them. Multi-database consistency is an application concern; distributed transactions are out of scope. Doctrine's driver and SQL dialect determine portability, and only SQLite is covered by live integration tests at this stage. Credentials remain outside version control.
