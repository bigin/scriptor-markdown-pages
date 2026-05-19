<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

/**
 * Map a URL path to a markdown file under `content/`.
 *
 * Resolution rules, in order:
 *
 * - `/<track>/`          → `content/<track>/_index.md`
 * - `/<track>/<a>/`      → `content/<track>/<a>.md`
 *                          else `content/<track>/<a>/_index.md`
 * - `/<track>/<a>/<b>/`  → `content/<track>/<a>/<b>.md`
 *                          else `content/<track>/<a>/<b>/_index.md`
 *
 * Each segment is sanitised to `[a-z0-9_-]` so a request can't traverse
 * out of the content root via `..` or absolute paths.
 */
final class Resolver
{
    /**
     * @param list<string> $trackSlugs Valid first-segment slugs (e.g. `developer-guide`).
     */
    public function __construct(
        private readonly string $contentRoot,
        private readonly array $trackSlugs,
    ) {}

    /**
     * @param list<string> $segments
     */
    public function resolve(array $segments): ?string
    {
        if ($segments === []) {
            return null;
        }
        $track = $segments[0];
        if (! in_array($track, $this->trackSlugs, true)) {
            return null;
        }

        $safe = [];
        foreach ($segments as $seg) {
            $clean = preg_replace('/[^a-z0-9_-]/i', '', $seg);
            if ($clean === '' || $clean === null) {
                return null;
            }
            $safe[] = strtolower($clean);
        }

        $base = rtrim($this->contentRoot, '/');

        if (count($safe) === 1) {
            $idx = $base . '/' . $safe[0] . '/_index.md';
            return is_file($idx) ? $idx : null;
        }

        $stem = $base . '/' . implode('/', $safe);
        if (is_file($stem . '.md')) {
            return $stem . '.md';
        }
        if (is_file($stem . '/_index.md')) {
            return $stem . '/_index.md';
        }
        return null;
    }

    public function contentRoot(): string
    {
        return $this->contentRoot;
    }

    /**
     * @return list<string>
     */
    public function trackSlugs(): array
    {
        return $this->trackSlugs;
    }
}
