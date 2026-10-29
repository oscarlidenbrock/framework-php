# ADR 0018: External resources in named asset libraries

Status: accepted

## Context

Named libraries already compose through `dependencies`, including libraries containing no files. Some sites need CSS or JavaScript hosted by a third party without making those packages mandatory framework dependencies. Local paths and publication must retain their existing behavior.

## Decision

A CSS or JS mapping may use a quoted HTTPS URL as its key when its options include `external: true`. External URLs are rejected as scalars and without that marker. The URL must be an absolute, valid HTTPS URL with a host and no credentials, fragment, whitespace, control characters or backslashes. URLs are never fetched or copied. The validated URL is the rendered URL; the same registry orders and deduplicates local and external definitions. Equal URLs with different type, slot or HTML attributes fail as conflicts. Font files remain local in this slice.

`integrity` is optional. When supplied for an external resource, `crossorigin` defaults to `anonymous`, since a cross-origin SRI fetch requires CORS. Authors may set `crossorigin` explicitly to an accepted value; `referrerpolicy` is optional and uses the existing validated values. CSS whose bytes change, including Google Fonts stylesheets, can omit `integrity`. Browser failure leaves that resource unavailable; a separate local library can supply fallback CSS or JS when its behavior is designed for graceful fallback. The framework does not automatically switch URLs after a failure.

A reusable set is an ordinary library whose `dependencies` name its member libraries. It needs no new manifest or grouping layer. Publication skips external entries and copies only validated local files. The public/admin theme lineage, response-local attachments and Twig `assets(type, slot)` behavior are unchanged.

## Alternatives

A separate external-assets key would duplicate the `css` and `js` processing pipeline. Automatic downloading would introduce network access during bootstrap or deployment and make output depend on remote state. Neither is adopted.

## Consequences

Extension authors must quote URL keys and mark each external entry. They remain responsible for third-party availability, licenses, content security policy and optional local fallbacks. The framework validates declarations and escapes generated HTML without contacting the host.
