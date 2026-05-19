<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads YAML frontmatter from a markdown file.
 *
 * A frontmatter block starts with `---` on its own line, ends with
 * `---` on its own line, and contains valid YAML in between. Anything
 * outside that block is the body. Files without frontmatter return an
 * empty array on `read()` and the original raw input as the body on
 * `parse()`.
 *
 * Shared by {@see Renderer} (which needs both frontmatter and body)
 * and {@see NavBuilder} (which only needs the frontmatter map). Keeps
 * the YAML parsing rules in one place.
 */
final class FrontmatterReader
{
    /**
     * Split a raw markdown string into its frontmatter map and body.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function parse(string $raw): array
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

    /**
     * Read a file and return only its frontmatter map. Missing file,
     * unreadable file, or absent frontmatter all return `[]`.
     *
     * @return array<string, mixed>
     */
    public function read(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if (! is_string($raw)) {
            return [];
        }
        return $this->parse($raw)[0];
    }
}
