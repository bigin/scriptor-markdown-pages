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
 * - `data.template`    `template:` from the .md's frontmatter when
 *                      present and slug-safe (`^[A-Za-z0-9_-]+$`);
 *                      otherwise the default `"markdown-section"`.
 *                      Themes that ship a matching `<template>.php`
 *                      pick it up; themes without it fall through to
 *                      `basic.php` and still render via
 *                      {@see ContentRendering}. The slug guard keeps
 *                      a hostile frontmatter value (`../../etc/passwd`)
 *                      from reaching the template loader.
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
        $template = self::templateFromFrontmatter($doc->frontmatter);

        $item = new Item(
            id:         null,
            categoryId: self::SYNTHETIC_CATEGORY_ID,
            name:       $title,
            label:      null,
            position:   0,
            active:     true,
            data:       [
                'slug'                => $slug,
                'template'            => $template,
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

    /**
     * Pick the template name from the .md frontmatter when it is
     * present and matches the slug shape that Scriptor's template
     * loader accepts. Anything else (missing key, non-string,
     * empty, slashes, dots, traversal) falls back to the default,
     * so a hostile frontmatter cannot steer the loader.
     *
     * @param array<string, mixed> $fm
     */
    private static function templateFromFrontmatter(array $fm): string
    {
        $candidate = $fm['template'] ?? null;
        if (\is_string($candidate) && preg_match('/^[A-Za-z0-9_-]+$/', $candidate) === 1) {
            return $candidate;
        }
        return self::TEMPLATE_NAME;
    }
}
