# ADR 0013: Explicit schema application from content administration

## Context

`ContentTypeManager::create()` currently creates metadata and applies the initial data table in one call. Administration needs to display pending definitions and a reviewed diff before changing physical content tables. The CLI and existing API callers rely on the current create behavior.

## Decision

Add an optional `applySchema` argument to `create()`, defaulting to true for compatibility. The administration passes false, so it persists the type and mandatory field definitions while leaving its data table pending. Its separate schema page shows `ContentSchemaManager::diff()`. A CSRF-protected POST invokes `apply(false)` only when every reported change is non-destructive. The application service and HTTP-aware managers require distinct structure management and schema application permissions. No web force option exists.

## Consequences

Definitions can be reviewed before physical application, while CLI behavior remains unchanged. The metadata fields table is still created when a type is registered. Content writes remain unavailable until the data schema is applied. Concurrent schema changes and driver-specific DDL failures remain governed by `ContentSchemaManager`; the web interface reports them without retrying or forcing a migration. Destructive changes require the existing CLI or a deliberate data migration.
