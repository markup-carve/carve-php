<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Include;

use Closure;
use MarkupCarve\Carve2\OptionError;

final class FileResolver
{
    public static function closure(string $root): Closure
    {
        if (!str_starts_with($root, '/')) {
            throw new OptionError('option includes: root must be absolute');
        }
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new OptionError('option includes: root is unavailable');
        }

        return static function (array $request) use ($canonicalRoot): array {
            $path = $request['path'];
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

            return ['text' => $text, 'id' => ltrim(substr($canonical, strlen($canonicalRoot)), '/')];
        };
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
