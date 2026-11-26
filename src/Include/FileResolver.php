<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

use Closure;
use MarkupCarve\Carve\OptionError;

final class FileResolver
{
    /**
     * @var int
     */
    private const CHUNK = 1 << 16;

    /**
     * The file resolver of C2-N-012 for an absolute root, as an `IncludeResolver`. `allowAbsolute` defaults to true;
     * false rejects an absolute include path before reading it.
     *
     * @param string $root
     * @param bool $allowAbsolute
     *
     * @return \MarkupCarve\Carve\Include\IncludeResolver
     */
    public static function forRoot(string $root, bool $allowAbsolute = true): IncludeResolver
    {
        $read = self::closure($root, $allowAbsolute);

        return new class ($read) implements IncludeResolver {
            public function __construct(private Closure $read)
            {
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array{text: string, id: string}|array{error: string, id?: string}
             */
            public function resolve(array $request): array
            {
                return ($this->read)($request);
            }
        };
    }

    public static function closure(string $root, bool $allowAbsolute = true): Closure
    {
        if (!str_starts_with($root, '/')) {
            throw new OptionError('option includes: root must be absolute');
        }
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new OptionError('option includes: root is unavailable');
        }

        return static function (array $request) use ($canonicalRoot, $allowAbsolute): array {
            $path = $request['path'];
            if (!$allowAbsolute && str_starts_with($path, '/')) {
                return ['error' => 'outside_root'];
            }
            if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $path) === 1) {
                return ['error' => 'outside_root'];
            }
            $from = $request['from'] ?? null;
            $base = $from === null ? $canonicalRoot : (str_starts_with($from, '/') ? dirname($from)
                : $canonicalRoot . '/' . dirname($from));
            $candidate = str_starts_with($path, '/') ? $path : $base . '/' . $path;
            $canonical = self::canonicalMissing($candidate);
            $inside = $canonicalRoot === '/' || ($canonical !== null
                && ($canonical === $canonicalRoot || str_starts_with($canonical, $canonicalRoot . '/')));
            if ($canonical === null || !$inside) {
                return ['error' => 'outside_root'];
            }
            if (!file_exists($canonical)) {
                return ['error' => 'not_found'];
            }
            if (!is_file($canonical)) {
                return ['error' => 'unreadable'];
            }
            $budget = is_int($request['budget'] ?? null) ? $request['budget'] : null;

            return self::read($canonical, ltrim(substr($canonical, strlen($canonicalRoot)), '/'), $budget);
        };
    }

    /**
     * C2-N-013: the opened handle is the tested path; C2-N-065: a file over the budget is not read whole.
     *
     * @param string $canonical
     * @param string $id
     * @param int|null $budget
     *
     * @return array{text: string, id: string}|array{error: string, id?: string}
     */
    private static function read(string $canonical, string $id, ?int $budget): array
    {
        $handle = @fopen($canonical, 'rb');
        if ($handle === false) {
            return ['error' => 'unreadable'];
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $canonical);
            $tested = @stat($canonical);
            if ($opened === false || $tested === false || $opened['dev'] !== $tested['dev'] || $opened['ino'] !== $tested['ino']) {
                return ['error' => 'unreadable'];
            }
            if ($budget !== null && $opened['size'] > $budget) {
                return self::isText($handle) ? ['error' => 'size_exceeded', 'id' => $id] : ['error' => 'non_text'];
            }
            $text = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if ($text === false) {
            return ['error' => 'unreadable'];
        }
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            return ['error' => 'non_text'];
        }

        return ['text' => $text, 'id' => $id];
    }

    /**
     * C2-N-011 in chunks: well-formed UTF-8 without U+0000, the bytes of a sequence cut by a chunk carried over.
     *
     * @param resource $handle
     *
     * @return bool
     */
    private static function isText($handle): bool
    {
        $carry = '';
        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK);
            if ($chunk === false) {
                return false;
            }
            $chunk = $carry . $chunk;
            $cut = strlen($chunk);
            $back = 0;
            while ($back < 3 && $cut - $back - 1 >= 0 && (ord($chunk[$cut - $back - 1]) & 0xC0) === 0x80) {
                $back++;
            }
            $lead = $cut - $back - 1;
            if ($lead >= 0 && ord($chunk[$lead]) >= 0xC0) {
                $need = ord($chunk[$lead]) >= 0xF0 ? 4 : (ord($chunk[$lead]) >= 0xE0 ? 3 : 2);
                if ($back + 1 < $need) {
                    $cut = $lead;
                }
            }
            $carry = substr($chunk, $cut);
            $part = substr($chunk, 0, $cut);
            if (str_contains($part, "\0") || !mb_check_encoding($part, 'UTF-8')) {
                return false;
            }
        }

        return $carry === '';
    }

    private static function canonicalMissing(string $path): ?string
    {
        $suffix = [];
        $prefix = $path;
        while (!file_exists($prefix) && !is_link($prefix)) {
            $parent = dirname($prefix);
            if ($parent === $prefix) {
                return null;
            }
            $suffix[] = basename($prefix);
            $prefix = $parent;
        }
        $real = realpath($prefix);
        if ($real === false) {
            return null;
        }
        foreach (array_reverse($suffix) as $part) {
            if ($part === '..') {
                $real = dirname($real);
            } elseif ($part !== '.') {
                $real .= '/' . $part;
            }
        }

        return $real;
    }
}
