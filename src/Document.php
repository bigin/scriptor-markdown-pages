<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

/**
 * Parsed markdown document. `title` and `summary` mirror the YAML
 * frontmatter when present; the renderer falls back to the first H1
 * for `title` when frontmatter is missing it. The raw decoded
 * frontmatter stays in `frontmatter` so templates can read custom
 * keys (e.g. `behind_the_scenes`) without going back to the parser.
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $frontmatter
     */
    public function __construct(
        public string $title,
        public string $summary,
        public string $html,
        public array $frontmatter,
        public string $sourcePath,
    ) {}
}
