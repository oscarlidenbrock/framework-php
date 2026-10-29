# Accounts, sessions and route access

Enable both `user` and `admin` in `enabled_modules`, set `admin_theme: admin_theme`, configure `config/database.yaml`, then explicitly apply migrations for the connection selected by `config/users.yaml`:

```sh
APP_WORKSPACE=development php bin/console database:migrate --yes
APP_WORKSPACE=development php bin/console user:create-admin admin@example.com
APP_WORKSPACE=development php bin/console theme:publish-assets
```

The password must be at least 12 characters. Supply it through a private environment or secret manager, never a command-line argument or versioned file. `user:create-admin` is CLI-only and succeeds only while the user table is empty. The reserved `administrator` role receives every permission currently declared by enabled modules, including modules enabled after the account was created; ordinary roles use explicit grants. The `user` module migration creates `users`, `roles`, `user_roles`, `role_permissions`, `auth_sessions` and `login_attempts`. Migrations are never run by HTTP. The user module currently registers its migration on every configured connection; `users.connection` selects which connection handles accounts for the active workspace. See ADR 0012.

The public `/login` route accepts GET and POST. `/register` is available only when `users.registration_enabled: true` in workspace-aware `config/users.yaml`; it is false by default. `/admin` requires `admin.access`. `/logout` accepts POST only. Login, registration and logout posts require a session-bound CSRF token. Invalid login responses use the same message for unknown accounts and wrong passwords. Five failed attempts per email and client IP block further attempts for 15 minutes. This is a basic throttle, not a distributed abuse prevention system.

Sessions use random, opaque, 256-bit cookie values. Only their SHA-256 hashes are stored in the database. The server stores the account ID, CSRF token and two-hour expiry. Login and logout rotate the session ID and invalidate the old session. Cookies are host-only, HttpOnly and SameSite=Lax, with Secure set for HTTPS requests. Serve authentication over HTTPS in production and configure trusted proxy handling correctly so the framework sees the original scheme. Session cookies include the workspace name, and the selected database stores that workspace's state. `HttpKernel` clears the current identity after every request, including error paths. A disabled account is not authenticated on its next request.

## Permissions

An enabled module may declare permissions in `config/permissions.yaml`:

```yaml
admin.access: Access administration dashboard
```

IDs must be unique, namespaced, lower case and at most 120 characters. A route in `config/routes.yaml` must declare exactly one access rule:

```yaml
example.page:
  path: /example
  methods: [GET]
  controller: example.controller
  access: public

example.account:
  path: /account
  methods: [GET]
  controller: example.account_controller
  access: authenticated

example.manage:
  path: /manage
  methods: [GET]
  controller: example.manage_controller
  access: permission:example.manage
```

Malformed, missing and undeclared permission rules fail boot. The kernel checks access before invoking the controller. Protected business operations must also call `AuthorizationInterface::requirePermission()`; `Module\Admin\Dashboard::view()` demonstrates this. `user.roles` provides authorized role creation, permission grants and role assignment. It requires `users.manage`, and grants are limited to declared permissions.

The content module adds protected administration routes and checks edit/delete ownership and publication permissions inside its application service and, during HTTP requests, Content Storage. Public content writes remain unavailable. See `docs/content.md` and ADR 0011. This phase has no MFA, password recovery or user administration screen. Expired session and throttle rows currently need periodic database maintenance; automated pruning is pending. MySQL/MariaDB behavior requires live verification before claiming compatibility.
