# ADR 0008: Per-content-type tables for scalar fields

Status: accepted

## Context

Dynamic content types need configurable scalar fields, SQL filtering and predictable storage without generated PHP classes. The storage format is difficult to reverse once content exists.

## Options considered

- Shared field-value tables (EAV): flexible definitions but costly joins, weak SQL types and difficult indexes.
- A table per content type: native SQL columns and straightforward queries, with explicit schema changes for each field.
- Hybrid scalar columns plus shared values: supports future complex fields but adds a second storage model before it is needed.

## Decision

Use `content_types` for type metadata and `content_{namespace}_fields` plus `content_{namespace}_data` for each type. Scalar field definitions determine DBAL columns. The five system fields use the same Field API and are protected. Attribute-mapped Doctrine ORM remains for code-defined entities only. The general `content_types` table is installed through a module migration; dynamic field tables and changes use Doctrine DBAL SchemaManager and Comparator after explicit CLI or service calls. Desired definitions are stored separately from physical schema state. Dangerous changes require confirmation and are refused while rows exist unless a deliberate data migration has resolved the risk. Renames and automatic column drops are deferred.

## Consequences

Queries use native SQL types and indexes, with no EAV joins or generated ORM mappings. Each content type has its own schema lifecycle. Adding fields has a DDL cost and must be applied explicitly. SQLite tests prove behavior there; MySQL schema behavior requires live integration testing before claiming parity. Multivalue fields, references, revisions and translations will need later design decisions.
