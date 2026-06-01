# scriptor-markdown-pages

File-system backed markdown pages for [Scriptor 2.x](https://scriptor-cms.dev).

URLs whose first segment matches a configured "track" slug (`/developer-guide/...`, `/user-guide/...`, etc.) resolve to `.md` files under a content directory. Pages are rendered with [CommonMark](https://commonmark.org/) + GFM + heading anchors and rendered through Scriptor's normal template chain. The plugin also exposes a read-only **Documentation** module in the editor sidebar so operators can browse the content tree from the admin UI.

This is the reference third-party plugin for the Scriptor Plugin API introduced in Scriptor 2.1. It uses `PageResolving`, `ContentRendering`, `registerEditorModule`, and `addEditorMenuItem`, every hook the API offers.

## Installation

This package is not on Packagist, so Composer has to be told where to find it with a one-time `repositories` entry, then required. From your Scriptor root (or any other project):

```bash
composer config repositories.scriptor-markdown-pages \
  vcs https://github.com/bigin/scriptor-markdown-pages
composer require bigins/scriptor-markdown-pages:^0.1
```

The first command adds a VCS repository to your `composer.json`; without it `composer require` reports *"Could not find a version of package …"*. Scriptor ships a clean `composer.json` with no plugin repositories declared, so this step is required there too.

In Docker, supply the repo URL and the package spec through the `SCRIPTOR_PLUGIN_REPOS` and `SCRIPTOR_PLUGINS` build args instead (see Scriptor's install docs).

Either way the plugin auto-registers via Composer's `installed.json` (Scriptor reads `type: scriptor-plugin` packages at boot). No code changes needed in your Scriptor install or theme; the next request picks the plugin up.

## Configuration

All keys optional, read from `data/settings/scriptor-config.php` (or the user's `custom.scriptor-config.php` override):

```php
return [
    // ... your other Scriptor config ...
    'plugins' => [
        'markdown_pages' => [
            'content_root'   => __DIR__ . '/../content',
            'tracks'         => ['user-guide', 'developer-guide', 'api'],
            'editor_enabled' => true,
            'editor_slug'    => 'docs',
            'editor_label'   => 'Documentation',
        ],
    ],
];
```

| Key | Default | Effect |
|---|---|---|
| `content_root` | `<scriptor-root>/content` | Where the markdown tree lives. |
| `tracks` | auto-discovered from `content_root` | URL first segments the plugin claims. If omitted, every immediate subdirectory of `content_root` that holds an `_index.md` becomes a track, alphabetically sorted. Pass `tracks => []` to opt out of plugin nav contributions entirely (useful when the plugin is installed but a theme should not surface markdown tracks in its top-nav). |
| `editor_enabled` | `true` | Register the Documentation module + menu entry in the editor. |
| `editor_slug` | `'docs'` | URL slug for the editor module. |
| `editor_label` | `'Documentation'` | Sidebar label (i18n key or literal). |

## Content layout

Each track lives in its own directory under `content_root`:

```
content/
├── developer-guide/
│   ├── _index.md
│   ├── welcome.md
│   └── concepts/
│       ├── _index.md
│       └── life-of-a-request.md
└── user-guide/
    ├── _index.md
    └── ...
```

URL → file resolution:

- `/<track>/` → `content/<track>/_index.md`
- `/<track>/<a>/` → `content/<track>/<a>.md` (else `content/<track>/<a>/_index.md`)
- `/<track>/<a>/<b>/` → `content/<track>/<a>/<b>.md` (else `content/<track>/<a>/<b>/_index.md`)

Each segment is sanitised to `[a-z0-9_-]` so URLs cannot traverse out of the content root.

## Frontmatter

YAML between two `---` fences at the top of every `.md`:

```yaml
---
title: "Building a Theme"
summary: "Build your first Scriptor theme from an empty directory."
---
```

The plugin reads `title` (for the page chrome H1), `summary` (for the meta description and listing/feed blurb), and `template` (to pick the theme template). Any other key is exposed via `$doc->frontmatter` if your templates pull it.

### SEO meta keys

These optional keys are copied onto the resolved page so a theme's `<head>` can read them as `$site->page-><key>` without re-parsing the file (only added when present):

| Key | On the page as | Use |
|---|---|---|
| `summary` | `$page->summary` | Meta-description default; also the listing/feed blurb. |
| `meta_title` | `$page->meta_title` | Override the `<title>` tag; defaults to `title` when absent. |
| `meta_description` | `$page->meta_description` | Override the meta description; falls back to `summary`. |
| `meta_keywords` | `$page->meta_keywords` | `<meta name="keywords">`. |

A theme picks its own precedence, e.g. `meta_description` → `summary` → site default. Most pages only need `summary`; the `meta_*` keys are a per-page override for when the SEO text should differ from the blurb.

When `title` is present, the leading `# Heading` in the body is stripped at render time so the chrome's H1 does not duplicate it (Hugo/Jekyll convention).

## Theme integration

The plugin synthesises a Scriptor `Page` DTO with template name `markdown-section` by default. Themes can either:

- **Provide a `markdown-section.php` template** for a custom layout (sidebar, breadcrumbs, etc.).
- **Let it fall through to `basic.php`**. The default rendering path will still call `$site->render('content')`, which dispatches `ContentRendering`, which this plugin substitutes with the pre-rendered HTML.

### Per-page template override

Any `.md` can pick a different template by setting `template:` in its frontmatter:

```yaml
---
title: "Building cathedrals"
template: blog-post
---
```

The value must match `^[A-Za-z0-9_-]+$` (so `blog-post`, `essay_long`, `Landing2` are fine; anything with a slash, dot, or other punctuation is rejected and the default kicks in). The theme then needs a matching `<name>.php`; if it is missing, the usual `basic.php` fallback still applies.

The plugin's CSS expectations match Scriptor's bundled themes (Prism for code blocks, UIkit base styles), but nothing is enforced.

## Sitemap / URL enumeration

The plugin owns the URL space of its markdown tree, so it also exposes a list of every routable URL for sitemap-style consumers. During boot it binds a `UrlEnumerator` into the DI container, already configured with the resolved content root and track list:

```php
use Bigins\ScriptorMarkdownPages\UrlEnumerator;

$enum = $container->get(UrlEnumerator::class);
foreach ($enum->all() as ['path' => $path, 'lastmod' => $ts]) {
    // $path  e.g. '/developer-guide/cookbook/sitemap-xml/'
    // $ts    unix mtime of the file that serves it (0 if unreadable)
}
```

Candidate paths are discovered by walking the content tree, then each is run back through the same `Resolver` the frontend uses, so the enumeration can never advertise a URL the site would 404 on and never drifts from the routing rules. Guard the lookup with `class_exists(UrlEnumerator::class) && $container->has(UrlEnumerator::class)` if your theme must also work when this plugin is absent.

## Editor module

When `editor_enabled` is true, a "Documentation" entry appears in the editor sidebar. It points at a read-only browser:

- `/<admin>/docs/` lists the top-level tracks.
- `/<admin>/docs/<seg>/...` descends into a track or shows a rendered page preview.

Edits are intentionally not supported here. Edit the `.md` files in your repository and let your deploy pipeline pick up the change. The editor view is a navigation aid, not an authoring surface.

## License

MIT
