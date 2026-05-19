<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use Scriptor\Boot\Editor\Editor;
use Scriptor\Boot\Editor\Module;

/**
 * Read-only browser for the markdown content tree inside the editor.
 *
 * Routes:
 *
 *   /editor/<slug>/                       directory listing for the
 *                                         content root (one section per
 *                                         track)
 *   /editor/<slug>/<seg>/...              directory listing or rendered
 *                                         page preview, depending on
 *                                         whether the path resolves to a
 *                                         file or a directory
 *
 * Writes do not happen here. Edits flow through Git (or whatever
 * source control the operator uses). The module exists so the editor
 * sidebar links somewhere when the markdown plugin is installed; the
 * page list mirrors the file system one-to-one.
 *
 * Render contract mirrors Scriptor's built-in modules: breadcrumbs go
 * on `$editor->breadcrumbs` (rendered by the editor chrome above the
 * page body), page title goes on `$editor->pageTitle` (drives the
 * browser tab), everything else lands on `$editor->pageContent`.
 */
final class DocumentationModule implements Module
{
    public function __construct(
        private readonly Editor $editor,
        private readonly Resolver $resolver,
        private readonly Renderer $renderer,
    ) {}

    public function execute(): void
    {
        // URL shape: /editor/<slug>/<segments...>; drop the leading slug.
        $segments = array_slice($this->editor->urlSegments->segments, 1);
        $segments = array_map(static fn ($s): string => (string) $s, $segments);

        $contentRoot = $this->resolver->contentRoot();
        if (! is_dir($contentRoot)) {
            $this->renderError(
                'No content directory',
                'Configured content root <code>' . htmlspecialchars($contentRoot, \ENT_QUOTES)
                . '</code> does not exist. Create it or adjust '
                . '<code>$config[\'plugins\'][\'markdown_pages\'][\'content_root\']</code>.',
            );
            return;
        }

        $safe = $this->sanitiseSegments($segments);
        if ($safe === null) {
            $this->renderError(
                'Invalid path',
                'Path segments may only contain letters, digits, dashes, and underscores.',
            );
            return;
        }

        $path = $contentRoot . ($safe === [] ? '' : '/' . implode('/', $safe));

        if (is_file($path . '.md')) {
            $this->renderDocument($path . '.md', $safe);
            return;
        }
        if (is_file($path . '/_index.md')) {
            $this->renderDocument($path . '/_index.md', $safe);
            return;
        }
        if (is_dir($path)) {
            $this->renderListing($path, $safe);
            return;
        }

        $this->renderError(
            'Not found',
            'No markdown file or directory at <code>'
            . htmlspecialchars(implode('/', $safe), \ENT_QUOTES) . '</code>.',
        );
    }

    /**
     * @param list<string> $segments
     */
    private function renderDocument(string $absolutePath, array $segments): void
    {
        $doc = $this->renderer->renderFile($absolutePath);
        $title = $doc->title !== '' ? $doc->title : (end($segments) ?: 'Documentation');

        $this->editor->pageTitle   = $title . ' - Scriptor';
        $this->editor->breadcrumbs = $this->breadcrumb($segments);

        $sourceRel = $this->relativise($absolutePath, $this->resolver->contentRoot());

        $this->editor->pageContent =
            '<h1>' . htmlspecialchars((string) $title, \ENT_QUOTES) . '</h1>'
            . '<p class="markdown-pages-source">Source: <code>'
            . htmlspecialchars($sourceRel, \ENT_QUOTES)
            . '</code>. Edits flow through Git, not this view.</p>'
            . '<div class="markdown-pages-document">' . $doc->html . '</div>';
    }

    /**
     * @param list<string> $segments
     */
    private function renderListing(string $dir, array $segments): void
    {
        $sectionName = $segments === [] ? 'Documentation' : (string) end($segments);

        $this->editor->pageTitle   = $sectionName . ' - Scriptor';
        $this->editor->breadcrumbs = $this->breadcrumb($segments);

        $entries = $this->scanDirectory($dir);

        $rows = '';
        if ($entries === []) {
            $rows = '<tr><td colspan="2"><em>Empty directory.</em></td></tr>';
        } else {
            foreach ($entries as $entry) {
                $href = $this->editor->siteUrl . '/' . $this->editorSlug()
                    . ($segments === [] ? '' : '/' . implode('/', $segments))
                    . '/' . $entry['slug'] . '/';
                $iconClass = $entry['kind'] === 'dir' ? 'gg-list' : 'gg-screen';
                $rows .= sprintf(
                    '<tr><td class="markdown-pages-icon"><i class="%s"></i></td>'
                    . '<td><a href="%s">%s</a></td></tr>',
                    htmlspecialchars($iconClass, \ENT_QUOTES),
                    htmlspecialchars($href, \ENT_QUOTES),
                    htmlspecialchars($entry['label'], \ENT_QUOTES),
                );
            }
        }

        $this->editor->pageContent =
            '<h1>' . htmlspecialchars($sectionName, \ENT_QUOTES) . '</h1>'
            . '<p>Read-only browser of the markdown content tree. Edits happen via Git.</p>'
            . '<table class="markdown-pages-listing"><tbody>' . $rows . '</tbody></table>';
    }

    private function renderError(string $title, string $bodyHtml): void
    {
        http_response_code(404);
        $this->editor->pageTitle   = $title . ' - Scriptor';
        $this->editor->breadcrumbs = $this->breadcrumb([]);
        $this->editor->pageContent =
            '<h1>' . htmlspecialchars($title, \ENT_QUOTES) . '</h1>'
            . '<p>' . $bodyHtml . '</p>';
    }

    /**
     * @param list<string> $segments
     */
    private function breadcrumb(array $segments): string
    {
        $root = $this->editor->siteUrl . '/' . $this->editorSlug() . '/';
        $items = [
            sprintf(
                '<li><a href="%s">Documentation</a></li>',
                htmlspecialchars($root, \ENT_QUOTES),
            ),
        ];
        $accum = [];
        foreach ($segments as $seg) {
            $accum[] = $seg;
            $href = $this->editor->siteUrl . '/' . $this->editorSlug() . '/' . implode('/', $accum) . '/';
            $items[] = sprintf(
                '<li><i class="gg-chevron-right"></i></li>'
                . '<li><a href="%s">%s</a></li>',
                htmlspecialchars($href, \ENT_QUOTES),
                htmlspecialchars($seg, \ENT_QUOTES),
            );
        }
        // The last entry collapses to a plain <span> so it does not
        // look like a clickable link to the page the user is already on.
        if (count($items) > 1) {
            $items[count($items) - 1] = preg_replace(
                '#<li><a href="[^"]*">(.+?)</a></li>$#',
                '<li><span>$1</span></li>',
                $items[count($items) - 1],
            ) ?? $items[count($items) - 1];
        } elseif ($segments === []) {
            $items = ['<li><span>Documentation</span></li>'];
        }
        return implode('', $items);
    }

    private function editorSlug(): string
    {
        return $this->editor->urlSegments->first() ?? 'docs';
    }

    /**
     * @param list<string> $segments
     * @return list<string>|null
     */
    private function sanitiseSegments(array $segments): ?array
    {
        $clean = [];
        foreach ($segments as $seg) {
            if (! preg_match('/^[a-z0-9_-]+$/i', $seg)) {
                return null;
            }
            $clean[] = strtolower($seg);
        }
        return $clean;
    }

    /**
     * @return list<array{kind: 'dir'|'file', slug: string, label: string}>
     */
    private function scanDirectory(string $dir): array
    {
        $entries = [];
        $handle = @opendir($dir);
        if ($handle === false) {
            return [];
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '_')) {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_dir($full)) {
                $entries[] = ['kind' => 'dir', 'slug' => $name, 'label' => $name];
                continue;
            }
            if (is_file($full) && str_ends_with($name, '.md')) {
                $slug = substr($name, 0, -3);
                $entries[] = ['kind' => 'file', 'slug' => $slug, 'label' => $slug];
            }
        }
        closedir($handle);

        usort($entries, static function (array $a, array $b): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'dir' ? -1 : 1;
            }
            return strcmp($a['label'], $b['label']);
        });

        return $entries;
    }

    private function relativise(string $absolute, string $root): string
    {
        if (str_starts_with($absolute, $root)) {
            return ltrim(substr($absolute, strlen($root)), '/');
        }
        return $absolute;
    }
}
