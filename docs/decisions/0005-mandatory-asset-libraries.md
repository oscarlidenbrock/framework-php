# ADR 0005: Mandatory asset libraries

Status: Accepted

## Context

Direct file selection in controllers and manifests cannot express reusable dependency graphs or keep extension resources centrally registered. The asset API is an extension contract, so replacing it is a breaking change.

## Options considered

- Retain direct asset selection beside named libraries. This creates two competing registration and rendering paths.
- Require named libraries in extension `config/libraries.yaml`, with explicit response attachments and theme global library lists.

## Decision

Use only named libraries. Module and theme manifests reject direct resource declarations, and `renderResponse()` rejects the old `css` and `js` keys. The registry loads library definitions for enabled modules and the active theme lineage. A stable depth-first topological resolver expands dependencies. An injectable asset manager records attachments for one response; the HTTP kernel and template renderer clear it after use. The existing normalizer, file publisher and deduplicating resource registry remain the sole implementations of their respective responsibilities. Twig functions delegate safe tag generation to one renderer.

## Consequences

Extensions must migrate declarations and controller attachments together. Missing, disabled, cyclic or conflicting libraries fail early. Source assets remain outside `public/` and require explicit publication. Library files are read once per bootstrap; no lookup rescans directories. No asset pipeline or Twig-time attachment is introduced.
