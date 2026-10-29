# ADR 0009: Content forms and field presentation

## Context

Dynamic content fields already validate and persist through `FieldTypeInterface` and `ContentStorage`. This phase needs field controls and Twig rendering without opening unauthenticated write routes. The repository targets PHP 8.4 and currently installs standalone Symfony 7.4 components. Symfony Form, Validator and Twig Bridge were considered; none is installed in the current dependency set. Adopting them together would require new Composer dependencies, add a second validation model and substantial form/CSRF/Twig integration for a service-only slice.

## Decision

Keep field validation in `FieldTypeInterface` and storage. Add small widget and formatter registries with module service tags. Widgets convert submitted control values; formatters supply template variables. Trusted module Twig templates render both, using the existing namespace and override rules. A form returns field errors and a `ContentRecord`; callers explicitly save valid records with `ContentStorage`. Display mode field lists live in workspace-aware `config/content.yaml`. No content write route is published.

## Consequences

The service API remains available without HTTP or a new Composer dependency. Future authenticated forms can wrap this API with access checks and CSRF protection. Form widgets are responsible for control conversion, while Field Types remain the single source of value validation. Presentation configuration is deliberately limited to mode field lists; richer per-type display settings can be introduced later without changing stored content schemas.
