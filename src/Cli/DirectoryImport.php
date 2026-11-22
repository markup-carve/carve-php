<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Import\HubImporter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Staged Markdown tree conversion. The target is published only after all entries and links are checked.
 */
final class DirectoryImport
{
    /**
     * @return array{files: int, assets: int, review: int, skipped: int, report: string}
     */
    public static function run(string $source, string $output): array
    {
        $root = realpath($source);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException("source is not a directory: $source");
        }
        HubImporter::requireLibrary('gfm');
        $final = self::futurePath($output);
        if (file_exists($final) || is_link($final)) {
            throw new RuntimeException("output already exists: $final");
        }
        if ($final === $root || str_starts_with($final, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('output must be outside the source directory');
        }
        $entries = self::entries($root);
        if (!in_array('markdown', array_column($entries, 'kind'), true)) {
            throw new RuntimeException('source contains no Markdown files');
        }
        $parent = dirname($final);
        if (!is_dir($parent) && !mkdir($parent, 0777, true)) {
            throw new RuntimeException("cannot create output parent: $parent");
        }
        $shell = $parent . '/.' . basename($final) . '-' . bin2hex(random_bytes(8));
        if (!mkdir($shell, 0700) || !mkdir($shell . '/out')) {
            throw new RuntimeException('cannot create temporary directory');
        }
        $stage = $shell . '/out';
        try {
            [$files, $assets, $skipped] = self::convert($root, $stage, $entries);
            $review = self::checkLinks($stage, $files, $assets);
            $report = [
                'schemaVersion' => 1,
                'sourceFormat' => 'markdown',
                'files' => $files,
                'assets' => $assets,
                'skipped' => $skipped,
                'review' => $review,
            ];
            $reportJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($stage . '/migration-report.json', $reportJson . "\n") === false) {
                throw new RuntimeException('cannot write migration report');
            }
            $claimed = PHP_OS_FAMILY !== 'Windows';
            if ($claimed && !@mkdir($final)) {
                throw new RuntimeException("output appeared during conversion: $final");
            }
            if (!@rename($stage, $final)) {
                if ($claimed) {
                    rmdir($final);
                }

                throw new RuntimeException("cannot create output: $final");
            }
            rmdir($shell);

            return [
                'files' => count($files),
                'assets' => count($assets),
                'review' => count($review),
                'skipped' => count($skipped),
                'report' => $final . '/migration-report.json',
            ];
        } catch (Throwable $error) {
            self::clean($shell);

            throw $error;
        }
    }

    private static function futurePath(string $path): string
    {
        $candidate = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $missing = [];
        while (!file_exists($candidate) && !is_link($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                throw new RuntimeException("cannot resolve output path: $path");
            }
            $name = basename($candidate);
            if ($name === '.' || $name === '..') {
                throw new RuntimeException("output path traverses a missing directory: $path");
            }
            array_unshift($missing, $name);
            $candidate = $parent;
        }
        $ancestor = realpath($candidate);
        if ($ancestor === false) {
            throw new RuntimeException("cannot resolve output path: $path");
        }

        return $ancestor . ($missing === [] ? '' : '/' . implode('/', $missing));
    }

    /**
     * @return array<array{path: string, kind: string, identity: array<int>|null}>
     */
    private static function entries(string $root): array
    {
        $out = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            $kind = $entry->isLink() || !$entry->isFile()
                ? 'skipped' : (preg_match('/\.(md|markdown)$/i', $entry->getFilename()) ? 'markdown' : 'asset');
            $stat = $kind === 'skipped' ? false : lstat($entry->getPathname());
            $out[] = ['path' => $path, 'kind' => $kind, 'identity' => $stat === false ? null : [$stat['dev'], $stat['ino']]];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * @param string $root
     * @param string $stage
     * @param array<array{path: string, kind: string, identity: array<int>|null}> $entries
     *
     * @throws \RuntimeException
     *
     * @return array{0: list<array{source: string, output: string, diagnostics: array<mixed>}>, 1: list<string>, 2: list<string>}
     */
    private static function convert(string $root, string $stage, array $entries): array
    {
        $files = $assets = $skipped = [];
        $used = ['migration-report.json' => true];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            if ($entry['kind'] === 'skipped') {
                $skipped[] = $path;

                continue;
            }
            $dest = $entry['kind'] === 'markdown' ? preg_replace('/\.(md|markdown)$/i', '.crv', $path) : $path;
            if ($dest === null) {
                throw new RuntimeException("invalid output path: $path");
            }
            if (!function_exists('mb_strtolower') && preg_match('/[^\x00-\x7F]/', $dest) === 1) {
                throw new RuntimeException('non-ASCII output names require mbstring');
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($dest, 'UTF-8') : strtolower($dest);
            if (isset($used[$key])) {
                throw new RuntimeException("two inputs map to the same output: $dest");
            }
            $used[$key] = true;
            $target = $stage . '/' . $dest;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
                throw new RuntimeException("cannot create directory for $dest");
            }
            [$handle, $mode] = self::openEntry($root . '/' . $path, $path, $entry['identity']);
            $ok = false;
            $input = false;
            try {
                if ($entry['kind'] === 'asset') {
                    $ok = file_put_contents($target, $handle);
                } else {
                    $input = stream_get_contents($handle);
                }
            } finally {
                fclose($handle);
            }
            if ($entry['kind'] === 'asset') {
                if ($ok === false || !chmod($target, $mode)) {
                    throw new RuntimeException("cannot copy $path");
                }
                $assets[] = $path;

                continue;
            }
            if ($input === false) {
                throw new RuntimeException("cannot read $path");
            }
            $result = Carve::importDocument($input, ['from' => 'gfm']);
            if (file_put_contents($target, $result->source) === false || !chmod($target, (0666 & ~umask()) & $mode)) {
                throw new RuntimeException("cannot write $dest");
            }
            $files[] = [
                'source' => $path,
                'output' => $dest,
                'diagnostics' => array_map(static fn ($d): array => $d->toArray(), $result->diagnostics),
            ];
        }

        return [$files, $assets, $skipped];
    }

    /**
     * @param string $input
     * @param string $path
     * @param array<int>|null $identity
     *
     * @throws \RuntimeException
     *
     * @return array{0: resource, 1: int}
     */
    private static function openEntry(string $input, string $path, ?array $identity): array
    {
        clearstatcache(true, $input);
        $before = @lstat($input);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $identity !== [$before['dev'], $before['ino']]) {
            throw new RuntimeException("changed during migration: $path");
        }
        $handle = @fopen($input, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot read $path");
        }
        $opened = fstat($handle);
        if ($opened === false || $identity !== [$opened['dev'], $opened['ino']]) {
            fclose($handle);

            throw new RuntimeException("changed during migration: $path");
        }

        return [$handle, $opened['mode'] & 0777];
    }

    /**
     * @param string $stage
     * @param list<array{source: string, output: string, diagnostics: array<mixed>}> $files
     * @param list<string> $assets
     *
     * @throws \RuntimeException
     *
     * @return array<string>
     */
    private static function checkLinks(string $stage, array &$files, array $assets): array
    {
        $outputs = array_fill_keys(array_merge(array_column($files, 'output'), $assets), true);
        $ids = [];
        $aliases = [];
        $needsIds = false;
        foreach ($files as $file) {
            $source = file_get_contents($stage . '/' . $file['output']);
            if ($source === false) {
                throw new RuntimeException('cannot read converted output');
            }
            $needsIds = $needsIds || str_contains($source, '#');
        }
        if ($needsIds) {
            foreach ($files as $file) {
                $source = file_get_contents($stage . '/' . $file['output']);
                if ($source === false) {
                    throw new RuntimeException('cannot read converted output');
                }
                $html = Carve::render($source, ['rawHtml' => true]);
                [$ids[$file['output']], $aliases[$file['output']]] = self::fragmentTargets($html);
            }
        }
        $review = [];
        foreach ($files as &$file) {
            if ($file['diagnostics'] !== []) {
                $review[] = $file['source'];
            }
            $path = $stage . '/' . $file['output'];
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException('cannot read converted output');
            }
            if (!str_contains($source, '[') && !str_contains($source, '<')) {
                $file['links'] = [];

                continue;
            }
            $tree = json_decode(Carve::encodeTree(Carve::parse($source)), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($tree)) {
                throw new RuntimeException('cannot read converted tree');
            }
            $changed = false;
            $links = [];
            self::rewriteNode($tree, $file['source'], $outputs, $ids, $aliases, $links, $changed);
            $file['links'] = $links;
            foreach ($links as $link) {
                if ($link['status'] === 'unresolved') {
                    $review[] = $file['source'];

                    break;
                }
            }
            if ($changed) {
                $mode = fileperms($path) & 0777;
                $json = json_encode($tree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $written = Carve::write(Carve::decodeTree($json));
                if (!chmod($path, $mode | 0200) || file_put_contents($path, $written) === false || !chmod($path, $mode)) {
                    throw new RuntimeException('cannot write converted output');
                }
            }
        }
        unset($file);
        $review = array_values(array_unique($review));
        sort($review, SORT_STRING);

        return $review;
    }

    /**
     * @return array{0: array<string, true>, 1: array<string, string|null>}
     */
    private static function fragmentTargets(string $html): array
    {
        $document = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        $ids = [];
        $xpath = new DOMXPath($document);
        $query = '//*[@id][not(ancestor::template)] | //a[@name][not(ancestor::template)]';
        foreach ($xpath->query($query) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                if ($element->hasAttribute('id')) {
                    $ids[$element->getAttribute('id')] = true;
                }
                if ($element->localName === 'a' && $element->hasAttribute('name')) {
                    $ids[$element->getAttribute('name')] = true;
                }
            }
        }
        $aliases = [];
        $headings = '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][@id]'
            . ' | //section[@id][./h1 or ./h2 or ./h3 or ./h4 or ./h5 or ./h6]';
        foreach ($xpath->query($headings) ?: [] as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }
            $id = $heading->getAttribute('id');
            if (preg_match('/-(?:[2-9]|[1-9]\d+)$/D', $id) === 1) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($id, 'UTF-8') : strtolower($id);
            $aliases[$key] = array_key_exists($key, $aliases) && $aliases[$key] !== $id ? null : $id;
        }

        return [$ids, $aliases];
    }

    /**
     * @param array<mixed> $node
     * @param string $source
     * @param array<string, true> $outputs
     * @param array<string, array<string, true>> $ids
     * @param array<string, array<string, string|null>> $aliases
     * @param array<array<string, string>> $links
     * @param bool $changed
     */
    private static function rewriteNode(
        array &$node,
        string $source,
        array $outputs,
        array $ids,
        array $aliases,
        array &$links,
        bool &$changed,
    ): void {
        self::checkRawNode($node, $source, $outputs, $ids, $aliases, $links);
        $key = match ($node['type'] ?? null) {
            'link' => 'href', 'image' => 'src', default => null
        };
        if ($key !== null && isset($node[$key]) && is_string($node[$key])) {
            $replacement = self::checkDestination($node[$key], $source, $outputs, $ids, $aliases, $links);
            if ($replacement !== null && $replacement !== $node[$key]) {
                $node[$key] = $replacement;
                $changed = true;
            }
        }
        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as &$child) {
                    if (is_array($child)) {
                        self::rewriteNode($child, $source, $outputs, $ids, $aliases, $links, $changed);
                    }
                }
                unset($child);
            } else {
                self::rewriteNode($value, $source, $outputs, $ids, $aliases, $links, $changed);
            }
        }
        unset($value);
    }

    /**
     * @param array<mixed> $node
     * @param string $source
     * @param array<string, true> $outputs
     * @param array<string, array<string, true>> $ids
     * @param array<string, array<string, string|null>> $aliases
     * @param array<array<string, string>> $links
     */
    private static function checkRawNode(array $node, string $source, array $outputs, array $ids, array $aliases, array &$links): void
    {
        if (
            !in_array($node['type'] ?? null, ['raw_inline', 'raw_block'], true)
            || ($node['format'] ?? null) !== 'html' || !is_string($node['value'] ?? null)
        ) {
            return;
        }
        foreach (self::rawUrls($node['value']) as $url) {
            $href = $url['target'];
            if ($url['manual']) {
                $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'raw HTML srcset needs manual review'];

                continue;
            }
            $replacement = self::checkDestination($href, $source, $outputs, $ids, $aliases, $links);
            if ($replacement !== null && $replacement !== $href) {
                $links[array_key_last($links)] = [
                    'target' => $href,
                    'status' => 'unresolved',
                    'reason' => 'raw HTML link needs manual rewrite',
                ];
            }
        }
    }

    /**
     * @param string $href
     * @param string $source
     * @param array<string, true> $outputs
     * @param array<string, array<string, true>> $ids
     * @param array<string, array<string, string|null>> $aliases
     * @param array<array<string, string>> $links
     */
    private static function checkDestination(
        string $href,
        string $source,
        array $outputs,
        array $ids,
        array $aliases,
        array &$links,
    ): ?string {
        if (preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $href) === 1) {
            return null;
        }
        if (str_starts_with($href, '/')) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'site-root path needs route mapping'];

            return null;
        }
        $parts = parse_url($href);
        if ($parts === false) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'invalid URL'];

            return null;
        }
        $rawPath = $parts['path'] ?? '';
        $relative = $rawPath === '' ? $source : (dirname($source) === '.' ? $rawPath : dirname($source) . '/' . $rawPath);
        $normalized = self::normalizePath(rawurldecode($relative));
        $mapped = $normalized === null ? null : preg_replace('/\.(md|markdown)$/i', '.crv', $normalized);
        if ($mapped === null || !isset($outputs[$mapped])) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'target missing from output'];

            return null;
        }
        $fragment = rawurldecode($parts['fragment'] ?? '');
        $resolved = self::resolveFragment($fragment, $mapped, $ids, $aliases);
        if ($resolved === null) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'fragment missing from target'];

            return null;
        }
        $newPath = preg_replace('/\.(md|markdown)$/i', '.crv', $rawPath);
        if ($newPath === null) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'invalid URL'];

            return null;
        }
        if ($mapped !== $normalized && $newPath === $rawPath) {
            $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'encoded extension needs manual rewrite'];

            return null;
        }
        $newHref = self::rewrittenHref($newPath, $parts, $fragment, $resolved);
        $links[] = ['target' => $href, 'status' => $newHref === $href ? 'valid' : 'rewritten'];

        return $newHref;
    }

    /**
     * @param string $path
     * @param array<string, mixed> $parts
     * @param string $fragment
     * @param string $resolved
     */
    private static function rewrittenHref(string $path, array $parts, string $fragment, string $resolved): string
    {
        $rawQuery = $parts['query'] ?? null;
        $rawFragment = $parts['fragment'] ?? null;
        $query = is_string($rawQuery) ? '?' . $rawQuery : '';
        $hash = is_string($rawFragment) ? '#' . ($resolved === $fragment ? $rawFragment : rawurlencode($resolved)) : '';

        return $path . $query . $hash;
    }

    /**
     * @param string $fragment
     * @param string $mapped
     * @param array<string, array<string, true>> $ids
     * @param array<string, array<string, string|null>> $aliases
     */
    private static function resolveFragment(string $fragment, string $mapped, array $ids, array $aliases): ?string
    {
        if ($fragment === '' || isset($ids[$mapped][$fragment])) {
            return $fragment;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($fragment, 'UTF-8') : strtolower($fragment);

        return $aliases[$mapped][$key] ?? null;
    }

    /**
     * @return list<array{target: string, manual: bool}>
     */
    private static function rawUrls(string $html): array
    {
        $document = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        $out = [];
        $query = '//*[@href or @src or @poster or @action or @formaction or @data or @srcset or @imagesrcset]';
        $query .= '[not(ancestor::template)]';
        $nodes = (new DOMXPath($document))->query($query);
        foreach ($nodes ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            foreach (['href', 'src', 'poster', 'data', 'action', 'formaction', 'srcset', 'imagesrcset'] as $name) {
                if (!self::rawAttributeApplies($element, $name)) {
                    continue;
                }
                $value = $element->getAttribute($name);
                if ($name === 'srcset' || $name === 'imagesrcset') {
                    if (stripos($value, 'data:') !== false) {
                        $out[] = ['target' => $value, 'manual' => true];
                    } else {
                        foreach (self::srcsetUrls($value) as $url) {
                            $out[] = ['target' => $url, 'manual' => false];
                        }
                    }
                } else {
                    $out[] = ['target' => self::normalizeUrl($value), 'manual' => false];
                }
            }
        }

        return $out;
    }

    private static function rawAttributeApplies(DOMElement $element, string $name): bool
    {
        if (!$element->hasAttribute($name)) {
            return false;
        }

        return match ($name) {
            'data' => $element->localName === 'object',
            'action' => $element->localName === 'form',
            'formaction' => in_array($element->localName, ['button', 'input'], true),
            default => true,
        };
    }

    private static function normalizeUrl(string $value): string
    {
        return str_replace(["\t", "\n", "\r"], '', trim($value, "\x00..\x20"));
    }

    /**
     * @return list<string>
     */
    private static function srcsetUrls(string $value): array
    {
        $out = [];
        $at = 0;
        $length = strlen($value);
        while ($at < $length) {
            while ($at < $length && (ctype_space($value[$at]) || $value[$at] === ',')) {
                $at++;
            }
            $start = $at;
            while ($at < $length && !ctype_space($value[$at])) {
                $at++;
            }
            $url = rtrim(substr($value, $start, $at - $start), ',');
            if ($url !== '') {
                $out[] = self::normalizeUrl($url);
            }
            while ($at < $length && $value[$at] !== ',') {
                $at++;
            }
            $at++;
        }

        return $out;
    }

    private static function normalizePath(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private static function clean(string $path): void
    {
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $walk = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
