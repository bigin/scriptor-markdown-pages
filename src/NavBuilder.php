<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use Imanager\Http\UrlSegments;
use Scriptor\Boot\Frontend\Nav\NavItem;

/**
 * Emits the frontend nav tree for the configured markdown tracks.
 *
 * Registered with Scriptor's FrontendNavRegistry via
 * {@see \Scriptor\Boot\Plugin\PluginContext::contributeFrontendNav()}.
 * The registry invokes this builder once per request with the current
 * {@see UrlSegments}; the returned NavItem list goes through the
 * theme's render layer.
 *
 * Shape:
 *  - One top-level NavItem per configured track. Position defaults to
 *    `index * 10` (config-array order, leaving room for other plugins
 *    to interleave), but a track's `_index.md` frontmatter may set an
 *    explicit `weight:` that overrides the default. Sub-items already
 *    sort by their own `weight` frontmatter; the top-level override
 *    makes the same rule apply consistently at every depth.
 *  - For the track whose slug matches the first URL segment ("active
 *    track") the builder walks `<contentRoot>/<track>/` recursively
 *    and fills `NavItem::$children`. Non-active tracks render with
 *    empty children so the theme can collapse them.
 *
 * Labels:
 *  - A directory's `_index.md` `title` frontmatter wins. Missing or
 *    empty falls back to the titleized slug ("developer-guide" →
 *    "Developer Guide"). Acronym-heavy slugs (e.g. "api") should ship
 *    an `_index.md` with a `title: "API Reference"` line.
 *
 * Sort order for children:
 *  - `weight` frontmatter (lower wins; matches Hugo convention)
 *  - then alphabetical by label as the tiebreaker
 *
 * We deliberately do NOT scan inactive tracks. A documentation tree
 * can grow large; the cost of stat'ing it on every request matters
 * for cold-cache navigation.
 */
final class NavBuilder
{
    /**
     * @param list<string> $tracks  ordered list of track slugs
     */
    public function __construct(
        private readonly string $contentRoot,
        private readonly array $tracks,
        private readonly FrontmatterReader $frontmatter = new FrontmatterReader(),
    ) {}

    /**
     * @return list<NavItem>
     */
    public function __invoke(UrlSegments $url): array
    {
        $activeTrack = $url->segments[0] ?? null;
        $items = [];
        foreach (array_values($this->tracks) as $index => $slug) {
            $slug     = (string) $slug;
            $trackDir = $this->contentRoot . '/' . $slug;
            $fm       = $this->frontmatter->read($trackDir . '/_index.md');
            $label    = $this->labelFromFrontmatter($fm) ?? $this->titleize($slug);
            $weight   = $this->intFromFrontmatter($fm, 'weight') ?? ($index * 10);
            $children = ($slug === $activeTrack && is_dir($trackDir))
                ? $this->scanDir($trackDir, '/' . $slug . '/')
                : [];
            $items[] = new NavItem(
                url:      '/' . $slug . '/',
                label:    $label,
                position: $weight,
                children: $children,
            );
        }
        return $items;
    }

    /**
     * Recursive child scan. Returns NavItems sorted by `weight`
     * frontmatter then label.
     *
     * @return list<NavItem>
     */
    private function scanDir(string $dir, string $urlPrefix): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $rows = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '_index.md') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $rows[] = $this->dirRow($path, $entry, $urlPrefix);
                continue;
            }
            if (is_file($path) && str_ends_with($entry, '.md')) {
                $rows[] = $this->fileRow($path, $entry, $urlPrefix);
            }
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] <=> $b['weight'];
            }
            return strcasecmp($a['item']->label, $b['item']->label);
        });
        return array_map(static fn (array $r): NavItem => $r['item'], $rows);
    }

    /**
     * @return array{weight: int, item: NavItem}
     */
    private function dirRow(string $path, string $slug, string $urlPrefix): array
    {
        $fm       = $this->frontmatter->read($path . '/_index.md');
        $label    = $this->labelFromFrontmatter($fm) ?? $this->titleize($slug);
        $weight   = $this->intFromFrontmatter($fm, 'weight') ?? \PHP_INT_MAX / 2;
        $children = $this->scanDir($path, $urlPrefix . $slug . '/');
        return [
            'weight' => $weight,
            'item'   => new NavItem(
                url:      $urlPrefix . $slug . '/',
                label:    $label,
                position: 0,
                children: $children,
            ),
        ];
    }

    /**
     * @return array{weight: int, item: NavItem}
     */
    private function fileRow(string $path, string $entry, string $urlPrefix): array
    {
        $slug   = substr($entry, 0, -3);
        $fm     = $this->frontmatter->read($path);
        $label  = $this->labelFromFrontmatter($fm) ?? $this->titleize($slug);
        $weight = $this->intFromFrontmatter($fm, 'weight') ?? \PHP_INT_MAX / 2;
        return [
            'weight' => $weight,
            'item'   => new NavItem(
                url:      $urlPrefix . $slug . '/',
                label:    $label,
                position: 0,
                children: [],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $fm
     */
    private function labelFromFrontmatter(array $fm): ?string
    {
        $title = $fm['title'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $fm
     */
    private function intFromFrontmatter(array $fm, string $key): ?int
    {
        $v = $fm[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        return null;
    }

    private function titleize(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
