# ADR 0012: Administrator role and newly declared permissions

## Context

The first administrator was granted only the permissions declared when the account was created. Enabling another module later left that administrator unable to reach its administration pages, even though the role was intended to administer the installation.

## Decision

The reserved `administrator` role implicitly receives every permission currently declared by enabled modules. Permission checks still reject unknown permission IDs and inactive accounts. Other roles continue to use explicit grants. The first-admin command creates and assigns this role; it no longer snapshots all current permissions into `role_permissions`.

## Consequences

An existing administrator gains access to new module capabilities when that module is enabled. Assigning the reserved role is therefore a high-trust operation protected by `users.manage`. Disabling a module removes its permissions from the registry, so even administrators cannot use undeclared permissions. This change does not grant anything to ordinary roles.
