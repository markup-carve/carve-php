<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use FilesystemIterator;
use MarkupCarve\Carve\Carve;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
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
            $report = ['schemaVersion' => 1, 'sourceFormat' => 'markdown', 'files' => $files, 'assets' => $assets, 'skipped' => $skipped, 'review' => $review];
            if (file_put_contents($stage . '/migration-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n") === false) {
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

            return ['files' => count($files), 'assets' => count($assets), 'review' => count($review), 'skipped' => count($skipped), 'report' => $final . '/migration-report.json'];
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
            array_unshift($missing, basename($candidate));
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
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            $kind = $entry->isLink() || !$entry->isFile() ? 'skipped' : (preg_match('/\.(md|markdown)$/i', $entry->getFilename()) ? 'markdown' : 'asset');
            $stat = $kind === 'skipped' ? false : lstat($entry->getPathname());
            $out[] = ['path' => $path, 'kind' => $kind, 'identity' => $stat === false ? null : [$stat['dev'], $stat['ino']]];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * @param array<array{path: string, kind: string, identity: array<int>|null}> $entries
     * @return array{0: array<array<string, mixed>>, 1: array<string>, 2: array<string>}
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
            $files[] = ['source' => $path, 'output' => $dest, 'diagnostics' => array_map(static fn ($d): array => $d->toArray(), $result->diagnostics)];
        }

        return [$files, $assets, $skipped];
    }

    /**
     * @param array<int>|null $identity
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
     * @param array<array<string, mixed>> $files
     * @param array<string> $assets
     * @return array<string>
     */
    private static function checkLinks(string $stage, array &$files, array $assets): array
    {
        $outputs = array_fill_keys(array_merge(array_column($files, 'output'), $assets), true);
        $review = [];
        foreach ($files as &$file) {
            $path = $stage . '/' . $file['output'];
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException('cannot read converted output');
            }
            $tree = json_decode(Carve::encodeTree(Carve::parse($source)), true, 512, JSON_THROW_ON_ERROR);
            $changed = false;
            $links = [];
            self::rewriteNode($tree, $file['source'], $outputs, $links, $changed);
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
                if (!chmod($path, $mode | 0200) || file_put_contents($path, Carve::write(Carve::decodeTree($json))) === false || !chmod($path, $mode)) {
                    throw new RuntimeException('cannot write converted output');
                }
            }
        }
        unset($file);
        sort($review, SORT_STRING);

        return $review;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $outputs
     * @param array<array<string, string>> $links
     */
    private static function rewriteNode(array &$node, string $source, array $outputs, array &$links, bool &$changed): void
    {
        $key = match ($node['type'] ?? null) { 'link' => 'href', 'image' => 'src', default => null };
        if ($key !== null && isset($node[$key]) && is_string($node[$key])) {
            $href = $node[$key];
            if (!str_starts_with($href, '#') && preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $href) !== 1) {
                $parts = parse_url($href);
                $rawPath = $parts['path'] ?? '';
                $relative = dirname($source) === '.' ? $rawPath : dirname($source) . '/' . $rawPath;
                $normalized = self::normalizePath($relative);
                $mapped = preg_replace('/\.(md|markdown)$/i', '.crv', $normalized);
                if ($mapped === null || !isset($outputs[$mapped])) {
                    $links[] = ['target' => $href, 'status' => 'unresolved', 'reason' => 'target missing from output'];
                } else {
                    $newPath = preg_replace('/\.(md|markdown)$/i', '.crv', $rawPath);
                    $newHref = $newPath . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
                    $links[] = ['target' => $href, 'status' => $newHref === $href ? 'valid' : 'rewritten'];
                    if ($newHref !== $href) {
                        $node[$key] = $newHref;
                        $changed = true;
                    }
                }
            }
        }
        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as &$child) {
                    if (is_array($child)) {
                        self::rewriteNode($child, $source, $outputs, $links, $changed);
                    }
                }
                unset($child);
            } else {
                self::rewriteNode($value, $source, $outputs, $links, $changed);
            }
        }
        unset($value);
    }

    private static function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private static function clean(string $path): void
    {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
