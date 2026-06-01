<?php

declare(strict_types=1);

namespace Bigins\ScriptorMarkdownPages;

/**
 * Enumerates every routable markdown URL under the configured tracks,
 * each with a `lastmod` timestamp, for sitemap-style consumers.
 *
 * {@see Plugin::register()} builds one instance from the already-resolved
 * content root + track list and binds it in the DI container, so a theme
 * or another plugin can list the plugin's URLs without re-deriving any of
 * that configuration:
 *
 *     $enum = $container->get(UrlEnumerator::class);
 *     foreach ($enum->all() as ['path' => $path, 'lastmod' => $ts]) {
 *         // $path  e.g. '/developer-guide/cookbook/sitemap-xml/'
 *         // $ts    unix mtime of the file that serves it
 *     }
 *
 * The URL→file mapping is NOT reimplemented here. Candidate paths are
 * discovered by walking the content tree, then each is run back through
 * the same {@see Resolver} the frontend uses. A path the Resolver would
 * 404 on is dropped, so the enumeration can never advertise a URL the
 * site won't actually serve, and it can't drift from the routing rules.
 */
final class UrlEnumerator
{
    /**
     * @param list<string> $tracks valid first-segment slugs
     */
    public function __construct(
        private readonly string $contentRoot,
        private readonly array $tracks,
    ) {}

    /**
     * Every routable markdown URL, each with the mtime of the file that
     * serves it. Paths are absolute and carry a trailing slash, matching
     * the frontend's canonical shape. Order follows track config order,
     * then filesystem order within a track; consumers that need a stable
     * sort should sort by `path` themselves.
     *
     * @return list<array{path: string, lastmod: int}>
     */
    public function all(): array
    {
        $resolver = new Resolver($this->contentRoot, $this->tracks);
        $base     = rtrim($this->contentRoot, '/');

        $out  = [];
        $seen = [];
        foreach ($this->tracks as $track) {
            $track    = (string) $track;
            $trackDir = $base . '/' . $track;
            // A track is only routable when its landing `_index.md`
            // exists; NavBuilder and the Resolver both gate on the same
            // file, so skipping here keeps the three in agreement.
            if (! is_file($trackDir . '/_index.md')) {
                continue;
            }
            foreach ($this->walk($track, $trackDir) as $segments) {
                $file = $resolver->resolve($segments);
                if ($file === null) {
                    continue;
                }
                $path = '/' . implode('/', $segments) . '/';
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $mtime = @filemtime($file);
                $out[] = [
                    'path'    => $path,
                    'lastmod' => $mtime !== false ? $mtime : 0,
                ];
            }
        }
        return $out;
    }

    /**
     * Yield the URL segment-list for the track landing page and every
     * markdown file / sub-directory beneath it, recursively. The caller
     * runs each list back through the Resolver, so this only has to
     * over-generate candidates, never get the file mapping exactly right.
     *
     * @param list<string> $prefix
     * @return iterable<list<string>>
     */
    private function walk(string $track, string $dir, array $prefix = []): iterable
    {
        $segments = $prefix === [] ? [$track] : $prefix;
        yield $segments;

        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.' || $entry === '_index.md') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                yield from $this->walk($track, $path, [...$segments, $entry]);
                continue;
            }
            if (str_ends_with($entry, '.md')) {
                yield [...$segments, substr($entry, 0, -3)];
            }
        }
    }
}
