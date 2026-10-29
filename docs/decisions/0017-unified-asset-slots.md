# ADR 0017: Unified Twig asset slots

Status: accepted

## Context

The public Twig contract has separate CSS and JavaScript functions, while layouts need to select both an asset type and its output location. Font files require the same library validation, publication and request-local deduplication as other resources.

## Decision

Replace the Twig functions with `assets(type, slot)`. Supported types are `css`, `js` and `fonts`; slots are lowercase machine IDs, including `head` and `body`. Each library entry can declare `position`, defaulting to `head` for CSS and fonts and `body` for JS. The renderer emits only resources matching both arguments and rejects unknown types or malformed slots. A custom slot is rendered only if a trusted layout calls it. Fonts are declared under `fonts` using paths below `assets/fonts/`, with supported `.woff2`, `.woff`, `.ttf` and `.otf` formats. They render as escaped font preload links and default to anonymous CORS. Font file options are validated with the other asset options. Publication still copies declared resources only.

## Alternatives

Adding `assets_fonts()` would add another function for every new resource group. A single untyped argument would make slot selection ambiguous. The two-argument form keeps type and output location explicit.

## Consequences

Layouts and custom themes must migrate calls to `assets('css', 'head')`, `assets('js', 'head')`, `assets('js', 'body')` and `assets('fonts', 'head')` as needed. The removed function names fail under Twig strict mode, revealing themes that still need migration. Library attachment, dependency order and per-request reset remain unchanged.
