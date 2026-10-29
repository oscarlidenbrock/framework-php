# ADR 0011: Trusted identity for content writes

## Context

Content Storage currently assigns anonymous owner 0 and accepts `published` from its callers. The new administration screens need authenticated ownership and a separate publishing permission. Existing CLI and service users must continue to work without an HTTP request.

## Decision

Content Storage receives the optional core `RequestSecurityInterface`, never a user-module implementation class. During an active HTTP request it derives the owner from the authenticated identity and checks content permissions before writes. On insertion it overwrites any owner value in the record. Editing and deletion require an any-content permission or an own-content permission with a matching persisted owner. Changing `published` requires `content.publish`, including direct Storage calls. Outside an HTTP request, Storage preserves its current trusted internal/CLI behavior: anonymous owner 0 and caller-controlled publication. Browser forms exclude managed fields; users without publish permission do not see the publication control. The content module owns its routes, controllers and policy; the admin module supplies navigation and layout.

## Consequences

HTTP code cannot evade access checks by bypassing a route and calling Storage directly. Content Storage remains usable without the user or admin module. Administrative ownership is set once on creation and is not transferred by editing. Existing records with owner 0 remain anonymous. Public content rendering reads only published records. This phase does not add a general content access matrix, revision history or an owner-transfer operation.
