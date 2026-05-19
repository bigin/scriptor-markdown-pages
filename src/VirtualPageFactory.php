<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use Imanager\Domain\Item;
use Scriptor\Boot\Frontend\Page;

/**
 * Build the synthetic {@see Page} that a markdown URL resolves to.
 *
 * The plugin does not own a real iManager row for a markdown document;
 * the file system is the source of truth. We still need a Page DTO so
 * Scriptor's render pipeline (template selection, sidebar nav, head
 * meta) has something to talk to.
 *
 * The synthetic Item carries:
 *
 * - `name`             page title (frontmatter, or first H1)
 * - `data.slug`        last URL segment (used by templates that link
 *                      back to themselves)
 * - `data.template`    "markdown-section" so themes that ship a
 *                      custom layout pick it up; themes without it
 *                      fall through to `basic.php` and still render
 *                      via {@see ContentRendering}
 * - `data.menu_title`  same as title (used by sidebar entries)
 * - `data.content`     empty (the real HTML lives in the HTML marker)
 * - `data.parent`      0 (markdown pages are flat for sidebar purposes)
 * - `data.HTML_MARKER` the pre-rendered HTML the plugin produces
 * - `data.SOURCE_MARKER` absolute path to the .md (debug aid)
 */
final class VirtualPageFactory
{
    public const HTML_MARKER   = '_scriptor_markdown_pages_html';
    public const SOURCE_MARKER = '_scriptor_markdown_pages_source';
    public const TEMPLATE_NAME = 'markdown-section';

    /**
     * Category id used for the synthetic Item. Items require a
     * categoryId >= 1; the value does not need to match a real
     * category row because the synthetic Item is never persisted.
     */
    public const SYNTHETIC_CATEGORY_ID = 1;

    /**
     * @param list<string> $urlSegments
     */
    public function build(Document $doc, array $urlSegments): Page
    {
        $slug = $urlSegments === [] ? '' : (string) $urlSegments[array_key_last($urlSegments)];
        $title = $doc->title !== '' ? $doc->title : 'Documentation';

        $item = new Item(
            id:         null,
            categoryId: self::SYNTHETIC_CATEGORY_ID,
            name:       $title,
            label:      null,
            position:   0,
            active:     true,
            data:       [
                'slug'                => $slug,
                'template'            => self::TEMPLATE_NAME,
                'menu_title'          => $title,
                'content'             => '',
                'parent'              => 0,
                'pagetype'            => '1',
                self::HTML_MARKER     => $doc->html,
                self::SOURCE_MARKER   => $doc->sourcePath,
            ],
            created: time(),
            updated: time(),
        );

        return new Page($item);
    }
}
