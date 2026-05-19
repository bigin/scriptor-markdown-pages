<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse a `.md` file with optional YAML frontmatter into a {@see Document}.
 *
 * Frontmatter starts and ends with a line that is exactly `---`. Whatever
 * sits between is decoded as YAML; the rest is rendered through CommonMark
 * with the GFM extension (tables, task lists, autolinks, strikethrough)
 * and heading-permalink anchors so deep-links into a doc page work.
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

    public function __construct()
    {
        $env = new Environment([
            'heading_permalink' => [
                'symbol'          => '#',
                'html_class'      => 'heading-anchor',
                'id_prefix'       => '',
                'fragment_prefix' => '',
                'insert'          => 'before',
            ],
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new HeadingPermalinkExtension());
        $this->converter = new MarkdownConverter($env);
    }

    public function renderFile(string $absolutePath): Document
    {
        $raw = (string) file_get_contents($absolutePath);
        return $this->renderString($raw, $absolutePath);
    }

    public function renderString(string $raw, string $sourcePath = ''): Document
    {
        [$frontmatter, $body] = $this->splitFrontmatter($raw);

        $title   = (string) ($frontmatter['title'] ?? $this->firstHeading($body) ?? '');
        $summary = (string) ($frontmatter['summary'] ?? '');

        if (isset($frontmatter['title'])) {
            $body = $this->stripLeadingH1($body);
        }

        $html = $this->converter->convert($body)->getContent();

        return new Document($title, $summary, $html, $frontmatter, $sourcePath);
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

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        if (! str_starts_with($raw, "---\n") && ! str_starts_with($raw, "---\r\n")) {
            return [[], $raw];
        }
        $body = substr($raw, 4);
        $end  = strpos($body, "\n---\n");
        if ($end === false) {
            $end = strpos($body, "\n---\r\n");
            if ($end === false) {
                return [[], $raw];
            }
            $closeLen = 6;
        } else {
            $closeLen = 5;
        }
        $yaml = substr($body, 0, $end);
        $rest = (string) substr($body, $end + $closeLen);

        try {
            $decoded = Yaml::parse($yaml);
        } catch (\Throwable) {
            return [[], $raw];
        }
        return [is_array($decoded) ? $decoded : [], $rest];
    }

    private function firstHeading(string $body): ?string
    {
        if (preg_match('/^#\s+(.+?)\s*$/m', $body, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
