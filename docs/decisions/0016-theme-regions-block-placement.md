# ADR 0016: Theme regions and block placement

Status: accepted

## Context

Themes need named presentation slots while modules need a stable way to contribute content. Existing pages extend the base layout and override `content`. Installation configuration already uses the workspace-keyed `config` table.

## Decision

A theme manifest declares `regions` as a map of region IDs to optional `parent` region IDs. A child theme inherits the parent's map and may replace individual definitions; the resulting graph is validated, including missing parents and cycles. Region templates are `@theme/regions/{id}.html.twig`, with normal child-to-parent lookup and a framework fallback. Layouts receive rendered region variables as Twig `Markup`. The `page` and `sidebar` regions render inside the legacy `content` block. Bundled page templates overriding that block call `parent()` once, keeping existing content visible while displaying placed blocks.

Enabled modules register implementations of `BlockInterface` through the `framework.block` service tag. The framework registry validates unique IDs and renders their trusted Twig template with escaped data. A block may name asset libraries, attached before asset preparation. The block module owns the `blocks.placements.{theme}` JSON setting in the existing workspace-keyed `config` table, its permissions and administration form. Each placement has a block ID, region ID, integer weight and enabled flag. Public and administration themes have separate keys. Missing blocks and regions fail validation on write; stale persisted placements fail closed on render.

## Alternatives

Putting HTML in theme manifests would mix presentation with data. Storing placements in files would require deployment writes and complicate workspace isolation. A new table would add a migration for a small configuration record. The existing config table fits this initial slice.

## Consequences

Region and block IDs are public contracts. Region overrides use the existing Twig precedence. The application must load placements for each request so an edit appears on the next request. Existing `content` blocks stay supported. Third-party overrides must call `parent()` to display regions; a region-driven page can use the base block without an override.
