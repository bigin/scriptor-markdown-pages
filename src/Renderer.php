<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Parse a `.md` file with optional YAML frontmatter into a {@see Document}.
 *
 * Frontmatter starts and ends with a line that is exactly `---`. Whatever
 * sits between is decoded as YAML; the rest is rendered through CommonMark
 * with the GFM extension (tables, task lists, autolinks, strikethrough).
 *
 * Heading anchors: every `<h1>`..`<h6>` in the rendered output gets an
 * `id` attribute derived from the heading text, so URL fragments still
 * jump to a specific section. We do NOT emit an extra visible link or
 * "#" symbol next to the heading; the previous HeadingPermalink
 * extension was dropped because the symbol cluttered the rendered
 * page (and could not be hidden from the DOM without leaving an
 * empty `<a>` element behind).
 *
 * Code-block highlighting is intentionally NOT done here. Themes wire
 * Prism (or another client-side highlighter) and pick up the
 * `<code class="language-…">` markers CommonMark emits.
 *
 * When frontmatter declares a `title`, the leading H1 of the body is
 * stripped so the chrome's page-header H1 does not duplicate it.
 * (Hugo/Jekyll convention: top-of-file H1 is editor-readability only.)
 */
final class Renderer
{
    private MarkdownConverter $converter;

    public function __construct(
        private readonly FrontmatterReader $frontmatter = new FrontmatterReader(),
    ) {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $this->converter = new MarkdownConverter($env);
    }

    public function renderFile(string $absolutePath): Document
    {
        $raw = (string) file_get_contents($absolutePath);
        return $this->renderString($raw, $absolutePath);
    }

    public function renderString(string $raw, string $sourcePath = ''): Document
    {
        [$frontmatter, $body] = $this->frontmatter->parse($raw);

        $title   = (string) ($frontmatter['title'] ?? $this->firstHeading($body) ?? '');
        $summary = (string) ($frontmatter['summary'] ?? '');

        if (isset($frontmatter['title'])) {
            $body = $this->stripLeadingH1($body);
        }

        $html = $this->converter->convert($body)->getContent();
        $html = $this->addHeadingIds($html);

        return new Document($title, $summary, $html, $frontmatter, $sourcePath);
    }

    /**
     * Post-process the rendered HTML to add an `id` attribute to every
     * `<h1>`..`<h6>` based on the heading text. Replaces the
     * HeadingPermalink extension: we want URL fragments to work
     * (`#welcome` jumps to the right section) but no visible anchor
     * link or `#` symbol next to the heading.
     *
     * Duplicate slug guard: if the same slug appears twice in one
     * document, the second one gets `-2`, the third `-3`, etc.
     */
    private function addHeadingIds(string $html): string
    {
        $seen = [];
        return (string) preg_replace_callback(
            '#<(h[1-6])>(.*?)</\1>#u',
            function (array $m) use (&$seen): string {
                $text = strip_tags($m[2]);
                $slug = $this->slugify($text);
                if ($slug === '') {
                    return $m[0];
                }
                $count = ($seen[$slug] ?? 0) + 1;
                $seen[$slug] = $count;
                $id = $count === 1 ? $slug : $slug . '-' . $count;
                return '<' . $m[1] . ' id="' . htmlspecialchars($id, \ENT_QUOTES) . '">'
                    . $m[2] . '</' . $m[1] . '>';
            },
            $html,
        );
    }

    private function slugify(string $text): string
    {
        $slug = mb_strtolower($text);
        $slug = (string) preg_replace('/[^a-z0-9\s_-]/u', '', $slug);
        $slug = (string) preg_replace('/[\s_-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function stripLeadingH1(string $body): string
    {
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '# ')) {
            $newlinePos = strpos($trimmed, "\n");
            if ($newlinePos === false) {
                return '';
            }
            return ltrim(substr($trimmed, $newlinePos + 1));
        }
        return $body;
    }

    private function firstHeading(string $body): ?string
    {
        if (preg_match('/^#\s+(.+?)\s*$/m', $body, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
