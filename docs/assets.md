# Asset libraries

Every CSS, JavaScript and font resource belongs to a named library. Put definitions in `config/libraries.yaml` inside a module or theme. A library ID is `{extension_id}/{library_name}`; IDs are lowercase letters, digits and underscores, starting with a letter. Module and theme extension IDs cannot collide. The framework registers libraries from enabled modules and the active theme with its parents. It does not attach every registered library.

```yaml
base:
  version: 1.0.0
  css:
    css/base.css:
      media: screen
      attributes:
        data-theme: default
  js:
    js/base.js:
      defer: true

editor:
  dependencies: [example/base]
  css: css/editor.css
  js:
    js/editor.js:
      type: module
  fonts: fonts/site.woff2
```

`version` is optional nonempty text. `dependencies` defaults to an empty list. A library may contain CSS, JavaScript, fonts, any combination or only dependencies. Each `css`, `js` or `fonts` value is either one local path scalar or a mapping whose keys are local file paths or marked external URLs and whose values are option mappings. Use `css/site.css: {}` when an entry in a multi-file mapping has no options. The `path` property and list entries are rejected. Local paths are relative to the owning extension's `assets/` directory, begin with `css/`, `js/` or `fonts/`, and end in `.css`, `.js`, `.woff2`, `.woff`, `.ttf` or `.otf` as appropriate. Absolute local paths, traversal, unmarked URLs, missing local files and linked files outside the source directory are rejected. The resolved local URL is `/assets/modules/{id}/{path}` or `/assets/themes/{id}/{path}`. Run `php bin/console module:publish-assets` and `php bin/console theme:publish-assets` after changing source assets and during deployment; publication copies only local files declared in libraries to ignored paths under `public/assets/`.

## Reusable sets and external resources

An ordinary library with only `dependencies` is a reusable set. For example, a module can attach one ID for separately defined Bootstrap, Font Awesome and its own styles:

```yaml
bootstrap:
  css:
    'https://cdn.example.test/bootstrap.css':
      external: true
font_awesome:
  css:
    'https://cdn.example.test/font-awesome.css':
      external: true
site_styles:
  css: css/site.css
complete:
  dependencies: [example/bootstrap, example/font_awesome, example/site_styles]
```

These example hostnames illustrate the format. Use actual provider URLs in a deployed library. The included `example` module defines `example/complete`, combining local `example/base` with `example/external_fonts`, a Google Fonts stylesheet. Attach the set using the existing `libraries` response key or `AssetManager::attachLibrary()`.

An external CSS or JS entry uses a **quoted HTTPS URL key** and `external: true` in its options. URL scalars, unmarked URLs, HTTP, protocol-relative URLs, credentials, fragments, control characters and backslashes are rejected. Font entries remain local. The URL is escaped in rendered HTML. External URLs are never fetched during registration, publication or rendering. Identical URLs with the same type, slot and attributes render once; conflicting definitions fail.

`integrity` is optional for external files. When supplied, it must be a valid SRI hash and `crossorigin` defaults to `anonymous`; `crossorigin` may also be set explicitly to an accepted value. `referrerpolicy` uses the same validated values as local files. Stylesheets whose content changes, such as Google Fonts responses, may omit `integrity`. If the browser cannot load an external file, that file is unavailable; the framework does not retry or switch URLs. CSS can supply a local font stack or other local fallback in a separate library. JavaScript fallback requires application code that guards use of the external script. The deployment's content security policy must allow the chosen host.

## File properties

| Property | CSS | JS | Fonts | Default and accepted values |
| --- | --- | --- | --- | --- |
| `media` | Yes | No | No | Omitted; nonempty text. |
| `defer` | No | Yes | No | `true` for classic scripts; boolean. |
| `async` | No | Yes | No | `false`; boolean. `async: true` implicitly disables `defer`. Both cannot be true. |
| `type` | Yes | Yes | Yes | Omitted; CSS accepts `text/css`; JS accepts `module`, `text/javascript` or `application/javascript`. Module scripts implicitly disable `defer`; explicit `defer: true` is rejected. Font type defaults from the extension and must match it. |
| `position` | Yes | Yes | Yes | `head` for CSS/fonts, `body` for JS; any lowercase slot ID such as `head`, `body` or `footer`. |
| `integrity` | Yes | Yes | No | Omitted; one or more valid space-separated SHA-256, SHA-384 or SHA-512 SRI hashes. |
| `crossorigin` | Yes | Yes | Yes | `anonymous` for fonts, omitted otherwise; `anonymous` or `use-credentials`. |
| `referrerpolicy` | Yes | Yes | No | Omitted; `no-referrer`, `no-referrer-when-downgrade`, `origin`, `origin-when-cross-origin`, `same-origin`, `strict-origin`, `strict-origin-when-cross-origin` or `unsafe-url`. |
| `fetchpriority` | Yes | Yes | Yes | Omitted; `high`, `low` or `auto`. |
| `attributes` | Yes | Yes | Yes | Empty mapping of extra lowercase HTML attribute names to strings, integers or booleans. `true` renders a bare attribute; `false` omits it. |

Extra attributes cannot override dedicated properties, `href`, `src`, `rel` or `style`, and cannot start with `on`. URL-like values with a scheme accept only `http:` or `https:`. Generated tags escape URLs and attribute values. Unknown properties, `null` and invalid combinations fail registration. When one library's script depends on another library's script, both must have predictable deferred execution (`defer` or native `type: module`), neither may be `async`, and a dependent script must share its dependency’s slot or render in `body` after a `head` dependency.

## Attachment, dependencies and rendering

Attach a library in a controller response:

```php
return $this->templates->renderResponse(
    '@example/index.html.twig',
    ['libraries' => ['example/base']],
    ['title' => 'Example'],
);
```

The second argument accepts only `libraries`; the third remains Twig variables and the optional fourth is the HTTP status. A service can instead inject `Framework\Asset\AssetManager` and call `$this->assets->attachLibrary('example/editor')` before rendering. Repeated attachment is idempotent. Theme global libraries attach automatically, parent before child. Only libraries requested by the theme, controller or service and their recursive dependencies are loaded. Missing libraries, dependencies of disabled modules and cycles raise errors. Dependency order is stable; unrelated libraries retain attachment order. The same URL with equivalent type and attributes renders once; incompatible definitions raise an error. Scripts with `async` retain browser async semantics and may be used in independent libraries.

Layouts render the resolved collection through Twig:

```twig
{{ assets('fonts', 'head') }}
{{ assets('css', 'head') }}
{{ assets('js', 'head') }}
{{ assets('js', 'body') }}
{{ assets('css', 'print') }}
```

The `assets(type, slot)` function produces escaped, safe HTML through the central renderer. Only assets whose type and slot match the call render; custom slots must be called explicitly by a trusted layout. Attachments resolve before Twig renders the first document section; Twig does not provide a late attachment function. The asset manager is reset after every HTTP request and every direct template render, including failures. The registry reads library files once per bootstrap; a new bootstrap picks up file changes, while production Twig and extension discovery caches retain their existing invalidation rules. No bundling or transformation occurs.
