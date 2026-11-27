<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * Text fields of an imported tree that the written abbreviation definitions turn into abbreviation nodes on read-back.
 */
final class AbbreviationLosses
{
    /**
     * @param array<string, mixed> $document
     * @param string $source
     * @param array<string, array<string, mixed>> $abbreviations
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function find(array $document, string $source, array $abbreviations): array
    {
        if ($abbreviations === []) {
            return [];
        }
        $read = json_decode(Carve::toJson($source), true, 4096, JSON_THROW_ON_ERROR);
        if (!is_array($read)) {
            return [];
        }
        $out = [];
        self::node($document, $read, '', $out);

        return $out;
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $read
     * @param string $path
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $out
     */
    private static function node(array $expected, array $read, string $path, array &$out): void
    {
        if (($expected['type'] ?? null) !== ($read['type'] ?? null)) {
            return;
        }
        foreach ($expected as $field => $value) {
            if (!is_array($value) || !is_array($read[$field] ?? null)) {
                continue;
            }
            $here = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$field);
            if (is_string($value['type'] ?? null)) {
                self::node($value, $read[$field], $here, $out);
            } elseif (array_is_list($value)) {
                self::list($value, $read[$field], $here, $out);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $read
     * @param string $path
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $out
     */
    private static function list(array $expected, array $read, string $path, array &$out): void
    {
        $j = 0;
        $offset = 0;
        foreach ($expected as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $here = $path . '/' . $i;
            if (($item['type'] ?? null) === 'text') {
                $value = $item['value'] ?? null;
                if (!is_string($value)) {
                    continue;
                }
                $changed = self::consume($value, $read, $j, $offset);
                if ($changed === true) {
                    $out[] = new Diagnostic(
                        'field-unspellable',
                        'warning',
                        'render-loss',
                        'Carve source cannot spell the value field of a text node here',
                        $here . '/value',
                    );
                }

                continue;
            }
            $actual = $read[$j] ?? null;
            if ($offset !== 0 || !is_array($actual)) {
                continue;
            }
            self::node($item, $actual, $here, $out);
            $j++;
        }
    }

    /**
     * Consume a text field from the read-back's text and abbreviation nodes. Null means another source change made the
     * two runs incomparable; only a proven abbreviation substitution is reported here.
     *
     * @param string $value
     * @param array<mixed> $read
     * @param int $j
     * @param int $offset
     */
    private static function consume(string $value, array $read, int &$j, int &$offset): ?bool
    {
        $changed = false;
        $used = 0;
        $length = strlen($value);
        while ($used < $length) {
            $node = $read[$j] ?? null;
            if (!is_array($node)) {
                return null;
            }
            $type = $node['type'] ?? null;
            $text = match ($type) {
                'text' => $node['value'] ?? null,
                'abbreviation' => $node['key'] ?? null,
                default => null,
            };
            if (!is_string($text) || $offset >= strlen($text)) {
                return null;
            }
            $take = min($length - $used, strlen($text) - $offset);
            if (substr($value, $used, $take) !== substr($text, $offset, $take)) {
                return null;
            }
            $changed = $changed || $type === 'abbreviation';
            $used += $take;
            $offset += $take;
            if ($offset === strlen($text)) {
                $j++;
                $offset = 0;
            }
        }

        return $changed;
    }
}
