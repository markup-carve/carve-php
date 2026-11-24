<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

/**
 * Direct HTML for a conservative core subset: paragraphs and tight `-` bullet lists with one nested level, holding
 * plain text, `*strong*` and code spans. No tree is built. Any document outside the subset returns null and takes the
 * full pipeline, which must render the subset byte-identically.
 */
final class CoreLayout
{
    /**
     * Every byte the subset can hold; nothing in it needs HTML escaping.
     *
     * @var string
     */
    private const SOURCE_BYTES = "\n abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,-*`";

    /**
     * @var string
     */
    private const TEXT_BYTES = ' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,';

    /**
     * @var string
     */
    private const WORD_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * @param string $source
     *
     * @return string|null The HTML, or null when the document leaves the subset.
     */
    public static function render(string $source): ?string
    {
        $len = strlen($source);
        if ($len === 0 || strspn($source, self::SOURCE_BYTES) !== $len) {
            return null;
        }
        $lines = explode("\n", $source);
        $n = count($lines);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if ($line === '') {
                $i++;

                continue;
            }
            if ($line[0] === '-') {
                $i = self::list($lines, $i, $n, $out);
            } else {
                $i = self::paragraph($lines, $i, $n, $out);
            }
            if ($i < 0) {
                return null;
            }
        }

        return $out === [] ? null : implode("\n", $out);
    }

    /**
     * @param array<int, string> $lines
     * @param int $i
     * @param int $n
     * @param array<int, string> $out
     *
     * @return int The line after the paragraph, or -1.
     */
    private static function paragraph(array $lines, int $i, int $n, array &$out): int
    {
        $parts = [];
        while ($i < $n && $lines[$i] !== '') {
            $html = self::inline($lines[$i]);
            if ($html === null) {
                return -1;
            }
            $parts[] = $html;
            $i++;
        }
        $out[] = '<p>' . implode("\n", $parts) . '</p>';

        return $i;
    }

    /**
     * A list ends only at a blank line followed by a paragraph or the end of the document; a blank between items, a
     * lazy line or any other indentation falls back.
     *
     * @param array<int, string> $lines
     * @param int $i
     * @param int $n
     * @param array<int, string> $out
     *
     * @return int The line after the list, or -1.
     */
    private static function list(array $lines, int $i, int $n, array &$out): int
    {
        $html = "<ul>\n";
        $nested = '';
        $open = false;
        while ($i < $n) {
            $line = $lines[$i];
            if ($line === '') {
                break;
            }
            if (str_starts_with($line, '- ')) {
                $item = self::itemText($line, 2);
                if ($item === null) {
                    return -1;
                }
                if ($nested !== '') {
                    $html .= $nested . "    </ul>\n  </li>\n";
                    $nested = '';
                } elseif ($open) {
                    $html .= "</li>\n";
                }
                $html .= '  <li>' . $item;
                $open = true;
            } elseif ($open && str_starts_with($line, '  - ')) {
                $item = self::itemText($line, 4);
                if ($item === null) {
                    return -1;
                }
                $nested .= ($nested === '' ? "\n    <ul>\n" : '') . '      <li>' . $item . "</li>\n";
            } else {
                return -1;
            }
            $i++;
        }
        $html .= $nested !== '' ? $nested . "    </ul>\n  </li>\n</ul>" : "</li>\n</ul>";
        $next = $i;
        while ($next < $n && $lines[$next] === '') {
            $next++;
        }
        if ($next < $n && $lines[$next][0] === '-') {
            return -1;
        }
        $out[] = $html;

        return $i;
    }

    private static function itemText(string $line, int $at): ?string
    {
        // An item starts with a letter, as the block pass reads every other first byte as a possible marker.
        if (!isset($line[$at]) || !ctype_alpha($line[$at])) {
            return null;
        }

        return self::inline(substr($line, $at));
    }

    /**
     * One line of inline content. Its first word holds only letters and digits, so it cannot be a marker.
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function inline(string $text): ?string
    {
        $len = strlen($text);
        $word = strspn($text, self::WORD_BYTES);
        if ($word === 0 || ($word < $len && $text[$word] !== ' ') || $text[$len - 1] === ' ' || str_contains($text, '  ')) {
            return null;
        }
        $plain = strspn($text, self::TEXT_BYTES);
        if ($plain === $len) {
            return str_contains($text, '..') ? null : $text;
        }
        $html = '';
        $from = 0;
        $j = $plain;
        while ($j < $len) {
            $ch = $text[$j];
            if (($ch !== '*' && $ch !== '`') || $text[$j - 1] !== ' ') {
                return null;
            }
            $close = strpos($text, $ch, $j + 1);
            if ($close === false || $close === $j + 1 || !self::spanEnd($text, $close + 1, $len)) {
                return null;
            }
            $body = substr($text, $j + 1, $close - $j - 1);
            if (
                $body[0] === ' ' || $body[-1] === ' ' || str_contains($body, '  ')
                || strspn($body, $ch === '*' ? ' ' . self::WORD_BYTES : self::TEXT_BYTES) !== strlen($body)
            ) {
                return null;
            }
            $lead = substr($text, $from, $j - $from);
            if (str_contains($lead, '..')) {
                return null;
            }
            $html .= $lead . ($ch === '*' ? '<strong>' . $body . '</strong>' : '<code>' . $body . '</code>');
            $from = $close + 1;
            $j = $from + strspn($text, self::TEXT_BYTES, $from);
        }
        $tail = substr($text, $from);
        if (str_contains($tail, '..')) {
            return null;
        }

        return $html . $tail;
    }

    /**
     * A closing span marker is followed by the end of the line, a space, `.` or `,`.
     */
    private static function spanEnd(string $text, int $at, int $len): bool
    {
        return $at === $len || $text[$at] === ' ' || $text[$at] === '.' || $text[$at] === ',';
    }
}
