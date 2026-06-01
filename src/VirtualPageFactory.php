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
 * - `data.summary` / `data.meta_title` / `data.meta_description` /
 *                      `data.meta_keywords` — optional SEO frontmatter,
 *                      copied through only when present so a theme can
 *                      build <head> meta without re-parsing the file
 */
final class VirtualPageFactory
{
    public const HTML_MARKER   = '_scriptor_markdown_pages_html';
    public const SOURCE_MARKER = '_scriptor_markdown_pages_source';
    public const TEMPLATE_NAME = 'markdown-section';

    /**
     * Optional SEO override keys read from frontmatter and copied onto
     * the synthetic page when present, for a theme's <head> meta. The
     * blurb (`summary`) comes from {@see Document::$summary} separately.
     *
     * @var list<string>
     */
    private const META_KEYS = ['meta_title', 'meta_description', 'meta_keywords'];

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

        $data = [
            'slug'                => $slug,
            'template'            => $template,
            'menu_title'          => $title,
            'content'             => '',
            'parent'              => 0,
            'pagetype'            => '1',
            self::HTML_MARKER     => $doc->html,
            self::SOURCE_MARKER   => $doc->sourcePath,
        ];

        // Carry optional SEO frontmatter onto the page so the theme's
        // <head> can read `$page->summary` / `$page->meta_*` instead of
        // re-parsing the file. Only present, non-empty string values are
        // added, so a theme's `?:` fallback chain (meta_description ->
        // summary -> default) stays predictable.
        if (\trim($doc->summary) !== '') {
            $data['summary'] = \trim($doc->summary);
        }
        foreach (self::META_KEYS as $key) {
            $value = self::stringFromFrontmatter($doc->frontmatter, $key);
            if ($value !== '') {
                $data[$key] = $value;
            }
        }

        $item = new Item(
            id:         null,
            categoryId: self::SYNTHETIC_CATEGORY_ID,
            name:       $title,
            label:      null,
            position:   0,
            active:     true,
            data:       $data,
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

    /**
     * Read a scalar frontmatter value as a trimmed string; anything
     * non-scalar (list, map, null, missing) collapses to an empty
     * string so the caller can skip it.
     *
     * @param array<string, mixed> $fm
     */
    private static function stringFromFrontmatter(array $fm, string $key): string
    {
        $value = $fm[$key] ?? null;
        return \is_scalar($value) ? \trim((string) $value) : '';
    }
}
