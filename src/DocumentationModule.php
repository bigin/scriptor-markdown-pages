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
            $this->renderError('Invalid path', 'Path segments may only contain letters, digits, dashes, and underscores.');
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
        $this->editor->pageTitle = ($doc->title !== '' ? $doc->title : 'Documentation') . ' - Scriptor';

        $breadcrumb = $this->breadcrumb($segments);
        $sourceLine = 'Source: <code>' . htmlspecialchars(
            $this->relativise($absolutePath, $this->resolver->contentRoot()),
            \ENT_QUOTES,
        ) . '</code>';

        $title = $doc->title !== '' ? $doc->title : end($segments);
        $title = $title !== false ? $title : 'Documentation';

        $this->editor->pageContent =
            '<div class="markdown-pages-preview">'
            . '<p class="uk-text-meta">' . $breadcrumb . '</p>'
            . '<h1>' . htmlspecialchars((string) $title, \ENT_QUOTES) . '</h1>'
            . '<p class="uk-text-meta">' . $sourceLine . '. Edits flow through Git, not this view.</p>'
            . '<hr>'
            . $doc->html
            . '</div>';
    }

    /**
     * @param list<string> $segments
     */
    private function renderListing(string $dir, array $segments): void
    {
        $this->editor->pageTitle = ($segments === [] ? 'Documentation' : end($segments)) . ' - Scriptor';

        $entries = $this->scanDirectory($dir);
        $breadcrumb = $this->breadcrumb($segments);

        $itemsHtml = '';
        if ($entries === []) {
            $itemsHtml = '<li><em>Empty directory.</em></li>';
        } else {
            foreach ($entries as $entry) {
                $href = $this->editor->siteUrl . '/' . $this->editorSlug()
                    . ($segments === [] ? '' : '/' . implode('/', $segments))
                    . '/' . $entry['slug'] . '/';
                $itemsHtml .= sprintf(
                    '<li><a href="%s">%s%s</a></li>',
                    htmlspecialchars($href, \ENT_QUOTES),
                    $entry['kind'] === 'dir' ? '📁 ' : '📄 ',
                    htmlspecialchars($entry['label'], \ENT_QUOTES),
                );
            }
        }

        $this->editor->pageContent =
            '<div class="markdown-pages-listing">'
            . '<p class="uk-text-meta">' . $breadcrumb . '</p>'
            . '<h1>' . htmlspecialchars($segments === [] ? 'Documentation' : (string) end($segments), \ENT_QUOTES) . '</h1>'
            . '<p>Read-only browser of the markdown content tree. Edits happen via Git.</p>'
            . '<ul class="markdown-pages-list">' . $itemsHtml . '</ul>'
            . '</div>';
    }

    private function renderError(string $title, string $bodyHtml): void
    {
        http_response_code(404);
        $this->editor->pageTitle = $title . ' - Scriptor';
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
        $crumbs = ['<a href="' . htmlspecialchars($root, \ENT_QUOTES) . '">Documentation</a>'];
        $accum = [];
        foreach ($segments as $seg) {
            $accum[] = $seg;
            $href = $this->editor->siteUrl . '/' . $this->editorSlug() . '/' . implode('/', $accum) . '/';
            $crumbs[] = '<a href="' . htmlspecialchars($href, \ENT_QUOTES) . '">' . htmlspecialchars($seg, \ENT_QUOTES) . '</a>';
        }
        return implode(' / ', $crumbs);
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
            // Directories first, then alphabetical.
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
