<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use League\Container\Container;
use Scriptor\Boot\Editor\Editor;
use Scriptor\Boot\Editor\Menu\MenuItem;
use Scriptor\Boot\Editor\Module;
use Scriptor\Boot\Events\Frontend\ContentRendering;
use Scriptor\Boot\Events\Frontend\PageResolving;
use Scriptor\Boot\Plugin\Plugin as ScriptorPlugin;
use Scriptor\Boot\Plugin\PluginContext;

/**
 * Main plugin entry point. Wires the frontend markdown route + the
 * read-only Documentation editor module through Scriptor's PSR-14
 * events and the editor extension registries.
 *
 * Configuration (all keys optional, picked up from
 * `$config['plugins']['markdown_pages']`):
 *
 *   content_root    string  Absolute path to the markdown tree.
 *                           Defaults to `<scriptor-root>/content`.
 *   tracks          list    Allowed first URL segments. If omitted,
 *                           every subdirectory of `content_root` that
 *                           contains an `_index.md` becomes a track,
 *                           alphabetically sorted. Pass `[]` to opt
 *                           out of plugin nav contributions entirely.
 *   editor_enabled  bool    Whether to register the Documentation
 *                           editor module + menu entry. Defaults true.
 *   editor_slug     string  URL slug for the editor module. Defaults
 *                           `docs`.
 *   editor_label    string  i18n key or literal label for the menu
 *                           entry. Defaults `Documentation`.
 */
final class Plugin implements ScriptorPlugin
{
    public function name(): string
    {
        return 'bigins/scriptor-markdown-pages';
    }

    public function version(): string
    {
        return '0.1.6';
    }

    public function register(PluginContext $context): void
    {
        $config = self::pluginConfig($context->container());
        $scriptorRoot = (string) $context->container()->get('scriptor.root');

        $contentRoot = (string) ($config['content_root']
            ?? $scriptorRoot . '/content');
        /** @var list<string> $tracks */
        $tracks = array_values((array) ($config['tracks']
            ?? self::discoverTracks($contentRoot)));

        $frontmatter    = new FrontmatterReader();
        $resolver       = new Resolver($contentRoot, $tracks);
        $renderer       = new Renderer($frontmatter);
        $pageFactory    = new VirtualPageFactory();
        $navBuilder     = new NavBuilder($contentRoot, $tracks, $frontmatter);

        // Frontend nav: contribute one top-level NavItem per track,
        // with the active track's filesystem children populated.
        // FrontendNavRegistry is responsible for merging contributions
        // from all plugins; this builder only knows its own tracks.
        $context->contributeFrontendNav($navBuilder);

        // Frontend: PageResolving + ContentRendering
        $context->subscribe(PageResolving::class, static function (PageResolving $event)
            use ($resolver, $renderer, $pageFactory): void {
            if ($event->resolution !== null) {
                return;
            }
            $segments = $event->urlSegments->segments;
            $file = $resolver->resolve($segments);
            if ($file === null) {
                return;
            }
            $doc = $renderer->renderFile($file);
            $event->resolution = $pageFactory->build($doc, $segments);
        });

        $context->subscribe(ContentRendering::class, static function (ContentRendering $event): void {
            if ($event->html !== null) {
                return;
            }
            $html = $event->page->item->data->get(VirtualPageFactory::HTML_MARKER);
            if (is_string($html)) {
                $event->html = $html;
            }
        });

        // Editor: read-only Documentation browser
        $editorEnabled = (bool) ($config['editor_enabled'] ?? true);
        if ($editorEnabled) {
            $editorSlug  = (string) ($config['editor_slug']  ?? 'docs');
            $editorLabel = (string) ($config['editor_label'] ?? 'Documentation');

            $context->registerEditorModule(
                $editorSlug,
                static fn (Container $c, Editor $e): Module
                    => new DocumentationModule($e, $resolver, $renderer),
            );

            $context->addEditorMenuItem(new MenuItem(
                slug:        $editorSlug,
                label:       $editorLabel,
                // css-gg ships a small icon subset; "gg-copy" reads as
                // stacked pages, which fits a documentation browser.
                icon:        'gg-copy',
                displayType: 'sidebar',
                position:    50,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function pluginConfig(Container $container): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $container->get('scriptor.config');
        $plugins = (array) ($config['plugins'] ?? []);
        $own = $plugins['markdown_pages'] ?? [];
        return is_array($own) ? $own : [];
    }

    /**
     * Auto-discover tracks from the content root.
     *
     * Returns the slug of every immediate subdirectory of
     * `$contentRoot` that contains an `_index.md` — matching the
     * Resolver's expectation that `/<track>/` maps to
     * `content/<track>/_index.md`. The `_index.md` requirement
     * keeps random directories (vendor, .git, build artefacts) out
     * of the nav.
     *
     * Sort is alphabetical for deterministic order. Themes that
     * want a specific order should set `tracks` explicitly in
     * config; an explicit `tracks` (including `[]`) bypasses this
     * helper.
     *
     * @return list<string>
     */
    private static function discoverTracks(string $contentRoot): array
    {
        if (! is_dir($contentRoot)) {
            return [];
        }
        $entries = @scandir($contentRoot);
        if ($entries === false) {
            return [];
        }
        $tracks = [];
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $dir = $contentRoot . '/' . $entry;
            if (! is_dir($dir) || ! is_file($dir . '/_index.md')) {
                continue;
            }
            $tracks[] = $entry;
        }
        sort($tracks);
        return $tracks;
    }
}
