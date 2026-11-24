<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Resolve\Slug;

/**
 * Direct HTML for a conservative core subset: plain ATX headings with their sections, paragraphs, one-paragraph block
 * quotes, fenced code, a lone `---` paragraph, reference definitions, and tight `-` bullet lists with one nested level;
 * inline text holds letters, digits, single spaces, `.`, `,`, `*strong*`, `/emphasis/`, code spans and links. No tree
 * is built. Any document outside the subset returns null and takes the full pipeline, which must render the subset
 * byte-identically.
 */
final class CoreLayout
{
    /**
     * Every byte a document without a fence can hold.
     *
     * @var string
     */
    private const SOURCE_BYTES = "\n abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,-*`/[]():#>";

    /**
     * A fence body may hold any printable ASCII byte.
     *
     * @var string
     */
    private const FENCED_SOURCE_BYTES = "\n !\"#$%&'()*+,-./0123456789:;<=>?@"
        . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /**
     * @var string
     */
    private const TEXT_BYTES = ' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,';

    /**
     * @var string
     */
    private const WORD_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Bytes of a link destination after its scheme; none needs attribute escaping.
     *
     * @var string
     */
    private const URL_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789./-';

    /**
     * @var string
     */
    private const LANGUAGE_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @var string
     */
    private const DEFINITION = '/^\[([A-Za-z0-9]+)\]: (\S*)$/m';

    /**
     * @var array<int, string>
     */
    private array $lines;

    private int $n;

    /**
     * @var array<int, string>
     */
    private array $out = [];

    /**
     * Levels of the open sections, outermost first.
     *
     * @var array<int, int>
     */
    private array $levels = [];

    /**
     * @var array<string, true>
     */
    private array $headings = [];

    /**
     * Reference keys the links use.
     *
     * @var array<string, true>
     */
    private array $keys = [];

    /**
     * @var array<string, true>
     */
    private array $taken = [];

    /**
     * @var array<string, int>
     */
    private array $suffix = [];

    /**
     * @param string $source
     * @param array<string, string> $defs Destination by reference key.
     * @param bool $fenced
     */
    private function __construct(string $source, private array $defs, private bool $fenced)
    {
        $this->lines = explode("\n", $source);
        $this->n = count($this->lines);
    }

    /**
     * @param string $source
     *
     * @return string|null The HTML, or null when the document leaves the subset.
     */
    public static function render(string $source): ?string
    {
        $len = strlen($source);
        $fenced = str_contains($source, '```');
        if ($len === 0 || strspn($source, $fenced ? self::FENCED_SOURCE_BYTES : self::SOURCE_BYTES) !== $len) {
            return null;
        }
        $defs = [];
        if (str_contains($source, ']: ')) {
            $defs = self::definitions($source);
            if ($defs === null) {
                return null;
            }
        }

        return (new self($source, $defs, $fenced))->blocks();
    }

    /**
     * @return string|null
     */
    private function blocks(): ?string
    {
        $blocks = 0;
        $i = 0;
        while ($i < $this->n) {
            $line = $this->lines[$i];
            if ($line === '') {
                $i++;

                continue;
            }
            $pad = str_repeat('  ', count($this->levels));
            $first = $line[0];
            if ($first === '#') {
                $i = $this->heading($i);
            } elseif ($line === '---') {
                // On the first block it could open frontmatter.
                if ($blocks === 0 || !$this->blankAt($i + 1)) {
                    return null;
                }
                $this->out[] = $pad . "<p>\u{2014}</p>";
                $i++;
            } elseif ($first === '-') {
                $i = $this->list($i, $pad);
            } elseif ($first === '>') {
                $i = $this->quote($i, $pad);
            } elseif ($first === '[') {
                $i = preg_match(self::DEFINITION, $line, $m) === 1 && isset($this->defs[$m[1]]) && $this->blankAt($i + 1) ? $i + 1 : -1;
            } elseif ($this->fenced && str_starts_with($line, '```')) {
                $i = $this->fence($i, $pad);
            } else {
                $i = $this->paragraph($i, $pad);
            }
            if ($i < 0) {
                return null;
            }
            $blocks++;
        }
        // A heading's text is a reference key of its own; a defined key that equals one is left to the resolver.
        if ($this->keys !== [] && array_intersect_key($this->keys, $this->headings) !== []) {
            return null;
        }
        $this->closeSections(1);

        return $this->out === [] ? null : implode("\n", $this->out);
    }

    /**
     * A plain heading opens a section; the sections of its level and deeper close first.
     *
     * @param int $i
     *
     * @return int The line after the heading, or -1.
     */
    private function heading(int $i): int
    {
        $line = $this->lines[$i];
        $level = strspn($line, '#');
        $text = substr($line, $level + 1);
        if ($level > 6 || ($line[$level] ?? '') !== ' ' || $this->inline($text) !== $text || !$this->blankAt($i + 1)) {
            return -1;
        }
        $this->closeSections($level);
        $pad = str_repeat('  ', count($this->levels));
        $id = $this->unique(Slug::of($text, 'keep-case'));
        $this->out[] = $pad . '<section id="' . $id . '">';
        $this->out[] = $pad . '  <h' . $level . '>' . $text . '</h' . $level . '>';
        $this->levels[] = $level;
        $this->headings[$text] = true;

        return $i + 1;
    }

    private function closeSections(int $level): void
    {
        $depth = count($this->levels);
        while ($depth > 0 && $this->levels[$depth - 1] >= $level) {
            array_pop($this->levels);
            $depth--;
            $this->out[] = str_repeat('  ', $depth) . '</section>';
        }
    }

    /**
     * @param string $source
     *
     * @return array<string, string>|null Destination by key, or null for a duplicate key or a destination outside the
     *   subset.
     */
    private static function definitions(string $source): ?array
    {
        preg_match_all(self::DEFINITION, $source, $matches, PREG_SET_ORDER);
        $defs = [];
        foreach ($matches as $m) {
            if (isset($defs[$m[1]]) || !self::url($m[2])) {
                return null;
            }
            $defs[$m[1]] = $m[2];
        }

        return $defs;
    }

    private static function url(string $url): bool
    {
        $at = str_starts_with($url, 'https://') ? 8 : (str_starts_with($url, 'http://') ? 7 : 0);
        $len = strlen($url);

        return $at > 0 && $len > $at && ctype_alnum($url[$at]) && strspn($url, self::URL_BYTES, $at) === $len - $at;
    }

    /**
     * C2-H-024, as `Resolver::unique`: a taken id gets `-2`, then `-3`, until it is free.
     *
     * @param string $id
     *
     * @return string
     */
    private function unique(string $id): string
    {
        $out = $id;
        $n = $this->suffix[$id] ?? 2;
        while (isset($this->taken[$out])) {
            $out = $id . '-' . $n;
            $n++;
        }
        $this->suffix[$id] = $n;
        $this->taken[$out] = true;

        return $out;
    }

    /**
     * @param int $at
     *
     * @return bool Whether the line is blank or past the end.
     */
    private function blankAt(int $at): bool
    {
        return $at >= $this->n || $this->lines[$at] === '';
    }

    /**
     * @param int $i
     * @param string $pad
     *
     * @return int The line after the paragraph, or -1.
     */
    private function paragraph(int $i, string $pad): int
    {
        $parts = [];
        while ($i < $this->n && $this->lines[$i] !== '') {
            $html = $this->inline($this->lines[$i]);
            if ($html === null) {
                return -1;
            }
            $parts[] = $html;
            $i++;
        }
        $this->out[] = $pad . '<p>' . implode("\n", $parts) . '</p>';

        return $i;
    }

    /**
     * One paragraph, every line behind `> `; a lazy line falls back.
     *
     * @param int $i
     * @param string $pad
     *
     * @return int The line after the quote, or -1.
     */
    private function quote(int $i, string $pad): int
    {
        $parts = [];
        while ($i < $this->n && str_starts_with($this->lines[$i], '> ')) {
            $html = $this->inline(substr($this->lines[$i], 2));
            if ($html === null) {
                return -1;
            }
            $parts[] = $html;
            $i++;
        }
        if ($parts === [] || !$this->blankAt($i)) {
            return -1;
        }
        $this->out[] = $pad . '<blockquote><p>' . implode("\n", $parts) . '</p></blockquote>';

        return $i;
    }

    /**
     * A backtick fence of exactly three, an optional lowercase language, a non-empty body and a bare closing fence.
     *
     * @param int $i
     * @param string $pad
     *
     * @return int The line after the closing fence, or -1.
     */
    private function fence(int $i, string $pad): int
    {
        $info = substr($this->lines[$i], 3);
        if ($info !== '' && (!ctype_lower($info[0]) || strspn($info, self::LANGUAGE_BYTES) !== strlen($info))) {
            return -1;
        }
        $body = [];
        $j = $i + 1;
        while ($j < $this->n && $this->lines[$j] !== '```') {
            $line = $this->lines[$j];
            if (str_contains($line, '```') || str_starts_with(ltrim($line, ' '), '~~~')) {
                return -1;
            }
            $body[] = $line;
            $j++;
        }
        if ($j >= $this->n || $body === [] || !$this->blankAt($j + 1)) {
            return -1;
        }
        $code = strtr(implode("\n", $body), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
        $class = $info === '' ? '' : ' class="language-' . $info . '"';
        $this->out[] = $pad . '<pre><code' . $class . '>' . $code . "\n</code></pre>";

        return $j + 1;
    }

    /**
     * A list ends only at a blank line followed by another block or the end of the document; a blank between items, a
     * lazy line or any other indentation falls back. One blank may separate an item from its nested list.
     *
     * @param int $i
     * @param string $pad
     *
     * @return int The line after the list, or -1.
     */
    private function list(int $i, string $pad): int
    {
        $html = "<ul>\n";
        $nested = '';
        $open = false;
        while ($i < $this->n) {
            $line = $this->lines[$i];
            if ($line === '') {
                if ($open && $nested === '' && $this->nestedAfterBlank($i)) {
                    $i++;

                    continue;
                }

                break;
            }
            if (str_starts_with($line, '- ')) {
                $item = $this->itemText($line, 2);
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
                $item = $this->itemText($line, 4);
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
        while ($next < $this->n && $this->lines[$next] === '') {
            $next++;
        }
        if ($next < $this->n && $this->lines[$next][0] === '-') {
            return -1;
        }
        $this->out[] = $pad === '' ? $html : $pad . str_replace("\n", "\n" . $pad, $html);

        return $i;
    }

    /**
     * The blank line sits between a top-level item line and the first line of its nested list.
     */
    private function nestedAfterBlank(int $i): bool
    {
        return $this->lines[$i - 1][0] === '-' && str_starts_with($this->lines[$i + 1] ?? '', '  - ');
    }

    /**
     * @param string $line
     * @param int $at
     *
     * @return string|null
     */
    private function itemText(string $line, int $at): ?string
    {
        // An item starts with a letter, as the block pass reads every other first byte as a possible marker.
        if (!isset($line[$at]) || !ctype_alpha($line[$at])) {
            return null;
        }

        return $this->inline(substr($line, $at));
    }

    /**
     * One line of inline content. Its first word holds only letters and digits, so it cannot be a marker.
     *
     * @param string $text
     *
     * @return string|null
     */
    private function inline(string $text): ?string
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
            if ($text[$j - 1] !== ' ') {
                return null;
            }
            $span = $text[$j] === '[' ? $this->link($text, $j, $len) : self::span($text, $j);
            if ($span === null || !self::spanEnd($text, $span[1] + 1, $len)) {
                return null;
            }
            $lead = substr($text, $from, $j - $from);
            if (str_contains($lead, '..')) {
                return null;
            }
            $html .= $lead . $span[0];
            $from = $span[1] + 1;
            $j = $from + strspn($text, self::TEXT_BYTES, $from);
        }
        $tail = substr($text, $from);
        if (str_contains($tail, '..')) {
            return null;
        }

        return $html . $tail;
    }

    /**
     * `*strong*`, `/emphasis/` or a code span on one line.
     *
     * @param string $text
     * @param int $j
     *
     * @return array{0: string, 1: int}|null The element and the offset of its closing marker.
     */
    private static function span(string $text, int $j): ?array
    {
        $ch = $text[$j];
        $name = match ($ch) {
            '*' => 'strong',
            '/' => 'em',
            '`' => 'code',
            default => null,
        };
        if ($name === null) {
            return null;
        }
        $end = strpos($text, $ch, $j + 1);
        if ($end === false || $end === $j + 1) {
            return null;
        }
        $body = substr($text, $j + 1, $end - $j - 1);
        if (
            $body[0] === ' ' || $body[-1] === ' ' || str_contains($body, '  ')
            || strspn($body, $ch === '`' ? self::TEXT_BYTES : ' ' . self::WORD_BYTES) !== strlen($body)
        ) {
            return null;
        }

        return ['<' . $name . '>' . $body . '</' . $name . '>', $end];
    }

    /**
     * `[text](url)` or `[text][key]` with a defined key; the text is plain.
     *
     * @param string $text
     * @param int $j
     * @param int $len
     *
     * @return array{0: string, 1: int}|null The anchor and the offset of its last byte.
     */
    private function link(string $text, int $j, int $len): ?array
    {
        $close = strpos($text, ']', $j + 1);
        if ($close === false || $close === $j + 1 || $close + 1 >= $len) {
            return null;
        }
        $label = substr($text, $j + 1, $close - $j - 1);
        if (
            $label[0] === ' ' || $label[-1] === ' ' || str_contains($label, '  ') || str_contains($label, '..')
            || strspn($label, self::TEXT_BYTES) !== strlen($label)
        ) {
            return null;
        }
        $open = $text[$close + 1];
        $end = match ($open) {
            '(' => strpos($text, ')', $close + 2),
            '[' => strpos($text, ']', $close + 2),
            default => false,
        };
        if ($end === false) {
            return null;
        }
        $target = substr($text, $close + 2, $end - $close - 2);
        if ($open === '(') {
            $url = self::url($target) ? $target : null;
        } else {
            $url = $this->defs[$target] ?? null;
            $this->keys[$target] = true;
        }

        return $url === null ? null : ['<a href="' . $url . '">' . $label . '</a>', $end];
    }

    /**
     * A closing span marker is followed by the end of the line, a space, `.` or `,`.
     */
    private static function spanEnd(string $text, int $at, int $len): bool
    {
        return $at === $len || $text[$at] === ' ' || $text[$at] === '.' || $text[$at] === ',';
    }
}
