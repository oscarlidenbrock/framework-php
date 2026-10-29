# ADR 0010: Account storage and route access

## Context

The standalone kernel has routes and a container but no authentication, authorization or HTTP session state. This change adds a public route configuration format and persisted accounts. Symfony FrameworkBundle and DoctrineBundle are outside the architecture. HttpFoundation provides request/response and cookie APIs; PHP provides password hashing.

## Decision

Every YAML route declares `access: public`, `access: authenticated`, or `access: permission:<id>`. Permission IDs are declared by enabled modules in `config/permissions.yaml`; unknown IDs and malformed rules fail during bootstrap. The kernel checks access before controller invocation. Protected operations repeat the check in their own service.

The `user` core module owns account, role, grant, session and login-attempt tables on its configured DBAL connection. Migrations are explicit. Session cookies hold random opaque IDs; only their hashes are stored in the database. The cookie name includes the workspace, and the server-side session records live in the workspace's selected database. Successful login rotates the ID. Login, registration and logout require a session-bound CSRF token. Registration is disabled by default. An explicit CLI command creates the initial administrator and grants the declared admin permission. The `admin` core module owns its dashboard and permission.

## Consequences

Enabling the user module makes its configured database available to HTTP requests. No content write route is introduced. This phase does not include password recovery, MFA, per-user account administration or content permissions. Cookie security depends on HTTPS deployment; production must serve authentication over HTTPS. The account schema and public route format are intentionally versioned by this ADR.
