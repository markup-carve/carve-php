<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;

/**
 * The inline nodes as Carve source (C2-W-069 to C2-W-080, C2-W-116). A line break of the content is a line feed in the
 * string; the block that holds the content places the lines.
 */
final class Inline
{
    /**
     * C2-W-071, C2-W-116: the nodes that have only a braced form.
     *
     * @var array<string, string>
     */
    private const BRACED = [
        'underline' => '_',
        'strike' => '~',
        'highlight' => '=',
        'superscript' => '^',
        'subscript' => ',',
        'insert' => '+',
        'delete' => '-',
    ];

    /**
     * @var array<string, string>
     */
    private const PAIRED = ['emphasis' => '/', 'strong' => '*'];

    /**
     * The first character of the written form of a node, where it does not depend on its fields.
     *
     * @var array<string, string>
     */
    private const FIRST = [
        'soft_break' => "\n",
        'emphasis' => '/',
        'strong' => '*',
        'link' => '[',
        'span' => '[',
        'footnote_ref' => '[',
        'image' => '!',
        'literal_inline' => '!',
        'inline_footnote' => '^',
        'code' => '`',
        'raw_inline' => '`',
        'math' => '$',
        'mention' => '@',
        'tag' => '#',
        'caption_number' => '#',
        'symbol' => ':',
        'inline_extension' => ':',
        'autolink' => '<',
        'xref' => '<',
        'non_breaking_space' => ' ',
    ];

    /**
     * The node written before the one being written in its list, null at the start of the list.
     */
    private mixed $before = null;

    public function __construct(private State $w)
    {
    }

    /**
     * The counts of `/` and `*` in the leaf, for the pairing test (C2-W-093).
     *
     * @param array<mixed> $nodes
     *
     * @return array<string, int>
     */
    public static function counts(array $nodes): array
    {
        $counts = ['/' => 0, '*' => 0];
        self::count($nodes, $counts);

        return $counts;
    }

    /**
     * @param array<mixed> $nodes
     * @param array<string, int> $counts
     *
     * @return void
     */
    private static function count(array $nodes, array &$counts): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = self::str($node, 'type');
            if ($type === 'text' && is_string($node['value'] ?? null)) {
                $counts['/'] += substr_count($node['value'], '/');
                $counts['*'] += substr_count($node['value'], '*');
            } elseif ($type === 'smart_punctuation' && is_string($node['source'] ?? null)) {
                $counts['/'] += substr_count($node['source'], '/');
            } elseif (isset(self::PAIRED[$type])) {
                $counts[self::PAIRED[$type]] += 2;
            }
            if (is_array($node['children'] ?? null)) {
                self::count($node['children'], $counts);
            }
        }
    }

    /**
     * Writes a list of inline nodes behind what `$c` says was written last.
     *
     * @param array<mixed> $nodes
     * @param string $path Path of the list, such as `/children/0/children`.
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next First character written behind the list, -1 at the end of the content.
     *
     * @return string
     */
    public function content(array $nodes, string $path, Context $c, int $next): string
    {
        if ($c->flat === null) {
            $c->flat = '';
            $c->labels = $this->w->labels;
            self::flatten($nodes, $path, $c);
        }
        $out = '';
        $count = count($nodes);
        for ($at = 0; $at < $count; $at++) {
            $node = $nodes[$at];
            if (!is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $following = $nodes[$at + 1] ?? null;
            if ($type === 'code' && BraceBlock::of($node) === '' && is_array($following) && ($following['type'] ?? '') === 'code') {
                [$part, $at] = $this->codeRun($nodes, $at, $path, $c);
            } else {
                $after = $at + 1 < $count && is_array($nodes[$at + 1]) ? self::first($nodes[$at + 1], $c) : $next;
                $part = $this->node($node, $path . '/' . $at, $c, $after, $nodes[$at - 1] ?? null);
            }
            $out .= $part;
        }

        return $out;
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return void
     */
    private static function flatten(array $nodes, string $path, Context $c): void
    {
        foreach ($nodes as $at => $node) {
            $type = is_array($node) ? self::str($node, 'type') : '';
            if ($type === 'text') {
                $c->flatAt[$path . '/' . $at] = strlen((string)$c->flat);
                $c->flat .= self::str($node, 'value');
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                $c->flat .= "\n";
            } else {
                $c->flatTypes[strlen((string)$c->flat)] = $type;
                $offset = strlen((string)$c->flat);
                $c->flatFirst[$offset] = is_array($node) ? self::first($node, $c) : -1;
                $atom = is_array($node) && !isset($node['attrs']) ? self::atom($node) : null;
                if ($atom !== null) {
                    $c->flatAtoms[$offset] = $atom;
                }
                if ($atom === null && is_array($node)) {
                    $c->flatNodes[$offset] = $node;
                }
                $c->flatBraces[$offset] = $atom === null && (!is_array($node) || isset($node['attrs'])
                    || in_array($type, ['span', 'raw_inline', 'comment', 'critic_comment'], true) || !isset(self::FIRST[$type]));
                $c->flat .= "\0";
            }
        }
    }

    /**
     * Every string a node holds, its own fields and those of its descendants, each closed by `\0`.
     *
     * @param array<mixed> $node
     *
     * @return string
     */
    public static function strings(array $node): string
    {
        $out = '';
        array_walk_recursive($node, function (mixed $v) use (&$out): void {
            if (is_string($v)) {
                $out .= $v . "\0";
            }
        });

        return $out;
    }

    /**
     * The mark a bare form of the type ends with, or null.
     *
     * @param string $type
     *
     * @return string|null
     */
    public static function bareMark(string $type): ?string
    {
        return self::PAIRED[$type] ?? null;
    }

    /**
     * Whether the writer knows how the type is spelled, so the brace test of `Text` can read past it.
     *
     * @param string $type
     *
     * @return bool
     */
    public static function known(string $type): bool
    {
        return isset(self::FIRST[$type]) || isset(self::BRACED[$type]) || isset(self::PAIRED[$type])
            || in_array($type, ['comment', 'critic_comment', 'hard_break', 'smart_punctuation', 'abbreviation'], true);
    }

    /**
     * C2-W-112: code spans with nothing between them are written as one span; each span behind the first is reported.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: string, 1: int}
     */
    private function codeRun(array $nodes, int $at, string $path, Context $c): array
    {
        $value = '';
        $end = $at;
        $last = [];
        for ($span = $nodes[$end] ?? null; is_array($span) && ($span['type'] ?? '') === 'code'; $span = $nodes[$end] ?? null) {
            if ($end > $at) {
                $this->w->lose($path . '/' . $end, 'code', null);
            }
            $value .= self::str($span, 'value');
            $last = $span;
            $end++;
            if (BraceBlock::of($span) !== '') {
                break;
            }
        }
        $out = self::codeSpan($value) . BraceBlock::of($last, ['pipes' => $c->inCell]);
        $this->moved($c, $out);

        return [$out, $end - 1];
    }

    /**
     * The nominal first character of the written form of `$node`, for the tests of the text before it.
     *
     * @param array<mixed> $node
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    public static function first(array $node, Context $c): int
    {
        $type = self::str($node, 'type');
        $text = match ($type) {
            'text' => self::str($node, 'value'),
            'smart_punctuation' => self::str($node, 'source'),
            'abbreviation' => self::str($node, 'key'),
            'hard_break' => $c->bareBreaks ? "\n" : '\\',
            'comment' => ($node['form'] ?? '') === 'braced' ? '{' : ' ',
            default => self::FIRST[$type] ?? '{',
        };

        return $text === '' ? -1 : Chars::at($text, 0);
    }

    /**
     * C2-W-022: before a line break or at the end of the content a backslash and a space are a hard break, so the
     * character itself is written and the node is reported.
     *
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function noBreakSpace(string $path, Context $c, int $next): string
    {
        if ($c->inCell || ($next !== -1 && $next !== 10)) {
            return '\\ ';
        }
        $this->w->lose($path, 'non_breaking_space', null);

        return "\u{00A0}";
    }

    /**
     * The whole spelling of an inline node that is one fixed token, or null.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function atom(array $node): ?string
    {
        return match (self::str($node, 'type')) {
            'symbol' => ':' . self::str($node, 'name') . ':',
            'tag' => '#' . self::str($node, 'name'),
            'mention' => '@' . self::str($node, 'target'),
            'non_breaking_space' => '\\ ',
            'smart_punctuation' => self::str($node, 'source'),
            'abbreviation' => self::str($node, 'key'),
            'caption_number' => '#',
            default => null,
        };
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $out
     *
     * @return void
     */
    private function moved(Context $c, string $out): void
    {
        if ($out === '') {
            return;
        }
        $c->last = Chars::before($out, strlen($out));
        $c->lineStart = $c->blockLines && $out[strlen($out) - 1] === "\n";
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param mixed $before
     *
     * @return string
     */
    private function node(array $node, string $path, Context $c, int $next, mixed $before): string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($type === 'text') {
            $points = $c->inCell ? ($this->w->wrapPoints[$path] ?? null) : null;
            $c->here = $points === null ? $c->flatAt[$path] ?? -1 : -1;
            $out = $points === null ? $this->text($node, $c, $next, $before) : $this->wrapped($node, $points, $c, $next);
            $c->here = -1;
            // C2-W-016: a CR at the end of a line is part of the line ending.
            if (str_ends_with($out, "\r") && ($next === 10 || $next === -1)) {
                $this->w->lose($path, 'text', 'value');
            }
            $this->moved($c, $out);

            return $out;
        }
        $this->before = $before;
        $out = $this->own($node, $type, $path, $c, $next);
        if ($out === null) {
            $this->w->lose($path, $type, null);
            $out = $this->children($node, $path, $c, $next, 'fallback');
        } elseif (!in_array($type, ['soft_break', 'hard_break', 'comment'], true)) {
            // C2-W-076: the attribute block is glued to its host. C2-I-040: a span takes the block with no part.
            $block = BraceBlock::of($node, ['pipes' => $c->inCell]);
            $out .= $type === 'span' && $block === '' ? '{}' : $block;
        }
        $this->moved($c, $out);

        return $out;
    }

    /**
     * C2-L-020: a cell text broken by continuation rows; the joining space before each fragment is the line break.
     *
     * @param array<mixed> $node
     * @param array<int> $points
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function wrapped(array $node, array $points, Context $c, int $next): string
    {
        $value = self::str($node, 'value');
        $out = '';
        $from = 0;
        sort($points);
        foreach ($points as $point) {
            if ($point < $from || $point > strlen($value)) {
                continue;
            }
            $piece = substr($value, $from, max(0, $point - 1 - $from));
            $out .= Text::escape($piece, $c, 10) . "\n";
            $c->last = 10;
            $from = $point;
        }

        return $out . Text::escape(substr($value, $from), $c, $next);
    }

    /**
     * @param array<mixed> $node
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param mixed $before
     *
     * @return string
     */
    private function text(array $node, Context $c, int $next, mixed $before): string
    {
        $value = is_string($node['value'] ?? null) ? $node['value'] : '';
        // C2-W-022: no space or tab of text stands at the end of a line.
        if ($next === 10 && !$c->bareBreaks) {
            $value = rtrim($value, " \t");
        } elseif ($next === -1) {
            $value = rtrim($value, " \t");
        }
        $prefix = '';
        // C2-W-125 case six: a name character behind a mention or a tag would lengthen its name.
        if (
            is_array($before) && in_array($before['type'] ?? '', ['mention', 'tag'], true) && $value !== ''
            && str_contains('_-.', $value[0]) && self::lengthens(self::str($before, $before['type'] === 'tag' ? 'name' : 'target'), $value)
        ) {
            $prefix = '\\' . $value[0];
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = Chars::at($prefix, 1);
            $c->lineStart = false;
        }
        // C2-I-117: `+-` needs its exact run, so a `+` or `-` behind it takes the backslash.
        $plusMinus = is_array($before) && ($before['type'] ?? '') === 'smart_punctuation' && ($before['source'] ?? '') === '+-';
        if ($plusMinus && $value !== '' && str_contains('+-', $value[0])) {
            $prefix .= '\\' . $value[0];
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = Chars::at($prefix, strlen($prefix) - 1);
        }
        // C2-W-094: a parenthesis glued behind a closing bracket would read as a link tail.
        if ($c->last === 0x5D && str_starts_with($value, '(')) {
            $prefix .= '\\(';
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = 0x28;
        }

        return $prefix . Text::escape($value, $c, $next);
    }

    /**
     * C2-I-092: whether the name a reader takes from the name and the text behind it is longer than the name. A name is
     * runs of letters, digits, `_` and `-` joined by single dots, and a trailing dot or hyphen is text.
     *
     * @param string $name
     * @param string $value
     *
     * @return bool
     */
    private static function lengthens(string $name, string $value): bool
    {
        preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*/', $name . $value, $m);

        return strlen(rtrim($m[0] ?? '', '.-')) > strlen($name);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param string $slot
     *
     * @return string
     */
    private function children(array $node, string $path, Context $c, int $next, string $slot = 'children'): string
    {
        $nodes = is_array($node[$slot] ?? null) ? $node[$slot] : (is_array($node['children'] ?? null) ? $node['children'] : []);
        if ($slot === 'fallback' && !is_array($node['fallback'] ?? null)) {
            $slot = 'children';
        }

        return $this->content($nodes, $path . '/' . $slot, $c, $next);
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function own(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        if (isset(self::PAIRED[$type])) {
            return $this->paired($node, $type, $path, $c, $next);
        }
        if (isset(self::BRACED[$type])) {
            return $this->braced($node, self::BRACED[$type], $path, $c);
        }

        return $this->spelledFromFields($node, $type, $path, $c, $next) ?? $this->spelledWithContent($node, $type, $path, $c, $next);
    }

    /**
     * C2-W-116: the nodes whose one spelling is made of their fields.
     *
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next First character written behind the node, -1 at the end of the content.
     *
     * @return string|null
     */
    private function spelledFromFields(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        return match ($type) {
            'soft_break' => "\n",
            'hard_break' => $c->bareBreaks && !$c->lineStart ? "\n" : "\\\n",
            'footnote_ref' => '[^' . self::labelText(self::str($node, 'key'), $c) . ']',
            'symbol' => $this->sigil($node, $type, $path, $c, $next, ':' . self::str($node, 'name') . ':'),
            'tag' => $this->sigil($node, $type, $path, $c, $next, '#' . self::str($node, 'name')),
            'non_breaking_space' => $this->noBreakSpace($path, $c, $next),
            'smart_punctuation' => $this->smart($node, $path, $c, $next),
            // C2-W-116: the mark of a caption number (C2-B-150); its number is a resolution result.
            'caption_number' => '#',
            'mention' => $this->sigil($node, $type, $path, $c, $next, $this->mention($node, $path)),
            'xref' => $this->xref($node, $path),
            'abbreviation' => $this->abbreviation($node, $path),
            default => null,
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function spelledWithContent(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        return match ($type) {
            'link' => $this->link($node, $path, $c),
            'image' => $this->image($node, $path, $c),
            'span' => $this->bracketed('[', $node, $path, $c),
            'inline_footnote' => $this->bracketed('^[', $node, $path, $c),
            'code' => $this->spanValue($node, $path, $c, ''),
            'literal_inline' => $this->spanValue($node, $path, $c, '!'),
            'math' => $this->spanValue($node, $path, $c, ($node['display'] ?? false) === true ? '$$' : '$'),
            'raw_inline' => $this->spanValue($node, $path, $c, '') . '{=' . self::str($node, 'format') . '}',
            'autolink' => $this->autolink($node, $path),
            'comment' => $this->comment($node, $path, $c),
            'critic_comment' => '{#' . $this->cellValue($node, $path, $c, self::str($node, 'value')) . '#}',
            default => $this->extensionOrCitation($node, $type, $path, $c, $next),
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function extensionOrCitation(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        if ($type === 'inline_extension') {
            return $this->extension($node, $path, $c, $next);
        }

        return $type === 'citation_group' ? $this->citations($node, $path) : null;
    }

    /**
     * @param array<mixed> $node
     * @param string $field
     * @param string $default
     *
     * @return string
     */
    public static function strOr(array $node, string $field, string $default): string
    {
        return is_string($node[$field] ?? null) ? $node[$field] : $default;
    }

    /**
     * @param array<mixed> $node
     * @param string $field
     *
     * @return string
     */
    public static function str(array $node, string $field): string
    {
        return is_string($node[$field] ?? null) ? $node[$field] : '';
    }

    /**
     * C2-W-069, C2-W-070: emphasis and strong, bare where that reads back and braced otherwise.
     *
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function paired(array $node, string $type, string $path, Context $c, int $next): string
    {
        $mark = self::PAIRED[$type];
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            $this->w->lose($path, $type, null);

            return '';
        }
        $prev = $c->last;
        $c->last = ord($mark);
        $c->lineStart = false;
        $open = $c->open;
        $c->open[$type] = ($c->open[$type] ?? 0) + 1;
        $inner = $this->content($children, $path . '/children', $c, ord($mark));
        $c->open = $open;
        $bare = !isset($open[$type]) && !self::holds($children, $type, $mark) && self::bareFits($inner, $mark, $prev, $next);

        return $bare ? $mark . $inner . $mark : '{' . $mark . $inner . $mark . '}';
    }

    /**
     * C2-I-047 to C2-I-050: the bare marks open and close at their places and are no run.
     *
     * @param string $inner
     * @param string $mark
     * @param int $prev
     * @param int $next
     *
     * @return bool
     */
    private static function bareFits(string $inner, string $mark, int $prev, int $next): bool
    {
        if ($inner === '' || $inner[0] === $mark || $inner[strlen($inner) - 1] === $mark) {
            return false;
        }
        $first = Chars::at($inner, 0);
        $last = Chars::before($inner, strlen($inner));
        if (Chars::isSpace($first) || Chars::isGlue($first) || Chars::isSpace($last) || $first === 0x5C && ($inner[1] ?? '') === ' ') {
            return false;
        }
        if ($prev === ord($mark) || Chars::isWord($prev) || $next === ord($mark) || Chars::isWord($next) || Chars::isGlue($next)) {
            return false;
        }

        // A backslash at the end of the content would escape the closer.
        return !str_ends_with($inner, '\\') || str_ends_with($inner, "\\\n");
    }

    /**
     * C2-W-070, C2-I-053: the content holds its own kind, or text with its own delimiter character.
     *
     * @param array<mixed> $nodes
     * @param string $type
     * @param string $mark
     *
     * @return bool
     */
    private static function holds(array $nodes, string $type, string $mark): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === $type || (($node['type'] ?? '') === 'text' && str_contains(self::str($node, 'value'), $mark))) {
                return true;
            }
            if (is_array($node['children'] ?? null) && self::holds($node['children'], $type, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function braced(array $node, string $mark, string $path, Context $c): string
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            $this->w->lose($path, self::str($node, 'type'), null);

            return '';
        }
        $c->last = ord($mark);
        $c->lineStart = false;

        return '{' . $mark . $this->content($children, $path . '/children', $c, ord($mark)) . $mark . '}';
    }

    /**
     * Content between brackets, as a span or an inline footnote (C2-W-116).
     *
     * @param string $open
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function bracketed(string $open, array $node, string $path, Context $c): string
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        // An empty span reads back only with its attributes behind it, as `[]{.c}`.
        if ($children === [] && ($node['type'] ?? '') === 'span' && ($node['attrs'] ?? []) === []) {
            $this->w->lose($path, 'span', null);

            return '';
        }

        $inner = $this->inside($children, $path . '/children', $c);
        // C2-W-125 case five: a caret at the start of span text would read as a footnote reference.
        if ($open === '[' && str_starts_with($inner, '^')) {
            $inner = '\\' . $inner;
        }

        return $open . $inner . ']';
    }

    /**
     * @param array<mixed> $children
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function inside(array $children, string $path, Context $c): string
    {
        $bracket = $c->inBracket;
        $c->inBracket = true;
        $c->last = 0x5B;
        $c->lineStart = false;
        $inner = $this->content($children, $path, $c, 0x5D);
        $c->inBracket = $bracket;

        return $inner;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function link(array $node, string $path, Context $c): string
    {
        $raw = $this->rawReference($node, $path, $c);
        if ($raw !== null) {
            return $raw;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        // C2-W-074: `[](/u)` is an empty link; a reference with no text has no label to read.
        if ($children === [] && isset($node['refForm'])) {
            $this->w->lose($path, 'link', null);

            return '';
        }
        $inner = $this->inside($children, $path . '/children', $c);
        // C2-W-125 case five: a caret at the start of link text would read as a footnote reference.
        if (str_starts_with($inner, '^')) {
            $inner = '\\' . $inner;
        }

        if (($node['refForm'] ?? '') === 'collapsed' && is_string($node['key'] ?? null) && Chars::fold($inner) !== $node['key']) {
            $inner = self::escapedLike($inner, $node['key']) ?? $inner;
        }

        return '[' . $inner . ']' . $this->target($node, self::str($node, 'href'), $c, $path);
    }

    /**
     * C2-W-075, C2-I-079: the content of a collapsed reference folds to its key, so it takes the backslashes the key
     * holds before ASCII punctuation; null where the two differ in more than those.
     *
     * @param string $content
     * @param string $key
     *
     * @return string|null
     */
    private static function escapedLike(string $content, string $key): ?string
    {
        $out = '';
        $k = 0;
        $n = strlen($content);
        $keyLength = strlen($key);
        for ($i = 0; $i < $n; $i++) {
            $ch = $content[$i];
            if ($ch === '\\' && $i + 1 < $n && substr($key, $k, 2) === '\\' . $content[$i + 1]) {
                $out .= substr($key, $k, 2);
                $k += 2;
                $i++;

                continue;
            }
            if ($k < $keyLength && $key[$k] === '\\' && ($key[$k + 1] ?? '') === $ch && Text::isPunct($ch)) {
                $out .= '\\' . $ch;
                $k += 2;

                continue;
            }
            $out .= $ch;
            $k++;
        }

        return Chars::fold($out) === $key ? $out : null;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function image(array $node, string $path, Context $c): string
    {
        $alt = self::str($node, 'alt');
        // C2-I-074: the alt text is the source between the brackets as it stands.
        if (substr_count($alt, '[') !== substr_count($alt, ']') || str_ends_with($alt, '\\')) {
            $this->w->lose($path, 'image', 'alt');
        }
        $spelled = $c->inCell ? $this->cellPipes($alt, $path) : $alt;

        return '![' . $spelled . ']' . $this->target($node, self::str($node, 'src'), $c, $path);
    }

    /**
     * C2-W-098: a pipe of verbatim text in a cell needs a backslash before it; one the text does not hold is a loss.
     *
     * @param string $text
     * @param string $path
     *
     * @return string
     */
    private function cellPipes(string $text, string $path): string
    {
        $out = (string)preg_replace('/(?<!\\\\)\|/', '\\\\|', $text);
        if ($out !== $text) {
            $this->w->lose($path, 'image', 'alt');
        }

        return $out;
    }

    /**
     * C2-W-075, C2-W-084: the verbatim text of a reference, where the sidecar holds it.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string|null
     */
    private function rawReference(array $node, string $path, Context $c): ?string
    {
        if (!isset($node['key'])) {
            return null;
        }
        $raw = $this->w->fact($path, 'rawRef');
        if (!is_string($raw)) {
            return null;
        }
        if (!str_contains($raw, "\n")) {
            return $raw;
        }
        if ($c->inCell) {
            return (string)preg_replace('/[ \t]*\n[ \t]*/', ' ', $raw);
        }

        return (string)preg_replace('/(?<!\\\\)[ \t]+\n/', "\n", $raw);
    }

    /**
     * C2-W-073, C2-W-074, C2-W-075: the link tail, or the label of a reference.
     *
     * @param array<mixed> $node
     * @param string $href
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $path
     *
     * @return string
     */
    private function target(array $node, string $href, Context $c, string $path): string
    {
        if (is_string($node['key'] ?? null)) {
            $key = $node['key'];

            return match ($node['refForm'] ?? 'full') {
                'collapsed' => '[]',
                'shortcut' => '',
                // C2-I-078: a label that starts with a caret or an at-sign is no reference label; the space folds away.
                default => '[' . (str_starts_with($key, '^') || str_starts_with($key, '@') ? ' ' : '') . self::labelText($key, $c) . ']',
            };
        }
        $tooltip = $node['tooltip'] ?? null;
        // C2-W-117: the tail stays on one line, so a title with a line break leaves the bracket pair as text.
        if (is_string($tooltip) && str_contains($tooltip, "\n")) {
            $this->w->lose($path, self::str($node, 'type'), null);
        }
        $title = is_string($tooltip) ? ' ' . BraceBlock::quoted($tooltip, $c->inCell) : '';

        return '(' . self::destination($href, $c->inCell) . $title . ')';
    }

    /**
     * C2-I-076, C2-I-079: a backslash at the end of a label would take its closing bracket; a space behind it folds away.
     *
     * @param string $key
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private static function labelText(string $key, Context $c): string
    {
        if ($c->inCell) {
            $key = str_replace('|', '\\|', $key);
        }

        return str_ends_with($key, '\\') ? $key . ' ' : $key;
    }

    /**
     * C2-I-013: a destination reads only the escaped parenthesis and the escaped backslash.
     *
     * @param string $value
     * @param bool $pipes
     *
     * @return string
     */
    public static function destination(string $value, bool $pipes = false): string
    {
        $unmatched = [];
        $open = [];
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            if ($value[$i] === '(') {
                $open[] = $i;
            } elseif ($value[$i] === ')' && array_pop($open) === null) {
                $unmatched[$i] = true;
            }
        }
        foreach ($open as $i) {
            $unmatched[$i] = true;
        }
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            $next = $value[$i + 1] ?? ')';
            $barePipe = $pipes && $ch === '|' && ($i === 0 || $value[$i - 1] !== '\\');
            if (isset($unmatched[$i]) || ($ch === '\\' && str_contains('()\\', $next)) || $barePipe) {
                $out .= '\\';
            }
            $out .= $ch === ' ' || $ch === "\n" ? '' : $ch;
        }

        return $out;
    }

    /**
     * C2-W-077: the shortest backtick run that does not occur in the value, padded where the ends need it.
     *
     * @param string $value
     *
     * @return string
     */
    public static function codeSpan(string $value): string
    {
        $taken = [];
        if (preg_match_all('/`+/', $value, $m)) {
            foreach ($m[0] as $run) {
                $taken[strlen($run)] = true;
            }
        }
        $length = 1;
        while (isset($taken[$length])) {
            $length++;
        }
        $run = str_repeat('`', $length);
        $padded = str_starts_with($value, '`') || str_ends_with($value, '`') || self::losesPadding($value);

        return $padded ? $run . ' ' . $value . ' ' . $run : $run . $value . $run;
    }

    /**
     * C2-I-002: spaces and tabs at the end of a line and the indent of the next are no part of the content. A space
     * behind an odd run of backslashes stays.
     *
     * @param string $value
     *
     * @return bool
     */
    private static function losesLineEdges(string $value): bool
    {
        for ($at = strpos($value, "\n"); $at !== false; $at = strpos($value, "\n", $at + 1)) {
            $after = $value[$at + 1] ?? '';
            $before = $at > 0 ? $value[$at - 1] : '';
            if ($after === ' ' || $after === "\t" || $before === "\t") {
                return true;
            }
            if ($before === ' ' && strspn(strrev(substr($value, 0, $at - 1)), '\\') % 2 === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-016: a reader would take one space from each end.
     *
     * @param string $value
     *
     * @return bool
     */
    private static function losesPadding(string $value): bool
    {
        return str_starts_with($value, ' ') && str_ends_with($value, ' ') && trim($value, ' ') !== '';
    }

    /**
     * C2-W-077, C2-W-117: a value that starts with a line break and ends with a backtick has no spelling.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $prefix
     *
     * @return string
     */
    private function spanValue(array $node, string $path, Context $c, string $prefix): string
    {
        $value = $this->cellValue($node, $path, $c, self::str($node, 'value'));
        if ($value === '') {
            // C2-W-116: an empty run pair reads back as text.
            $this->w->lose($path, self::str($node, 'type'), null);
        } elseif ((str_starts_with($value, "\n") && str_ends_with($value, '`')) || self::losesLineEdges($value)) {
            $this->w->lose($path, self::str($node, 'type'), 'value');
        }

        return $prefix . self::codeSpan($value);
    }

    /**
     * C2-W-067: no form crosses a row of a pipe table, so a line feed in a cell is written as a space and reported.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $value
     *
     * @return string
     */
    private function cellValue(array $node, string $path, Context $c, string $value): string
    {
        if (!str_contains($value, "\n")) {
            return $value;
        }
        if (!$c->inCell) {
            $lines = array_slice(explode("\n", $value), 1);
            $last = count($lines) - 1;
            foreach ($lines as $at => $line) {
                // The closing run follows the last line, so an empty last line is no blank line; a pad before the run
                // would be indent (C2-I-002).
                if ($at === $last && $line === '') {
                    $spelled = self::codeSpan($value);
                    $line = substr($spelled, (int)strrpos($spelled, "\n") + 1);
                    $line = str_starts_with($line, ' ') ? '' : $line;
                }
                if (Text::opensBlock($line)) {
                    $this->w->lose($path, self::str($node, 'type'), 'value');

                    break;
                }
            }

            return $value;
        }
        $this->w->lose($path, self::str($node, 'type'), 'value');

        return str_replace("\n", ' ', $value);
    }

    /**
     * C2-I-090: a sigil after a letter or number starts no form, so the node reads back as text. C2-I-092: a letter or
     * digit behind a mention or a tag lengthens its name, and no backslash keeps it out (C2-W-125 case six).
     *
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param string $out
     *
     * @return string
     */
    private function sigil(array $node, string $type, string $path, Context $c, int $next, string $out): string
    {
        if (!$c->lineStart && Chars::isWord($c->last)) {
            $this->w->lose($path, $type, null);
        } elseif ($type !== 'symbol' && $next >= 0 && $next < 0x80 && Chars::isWord($next)) {
            $this->w->lose($path, $type, $type === 'tag' ? 'name' : 'target');
        }

        return $out;
    }

    /**
     * C2-I-104, C2-I-105: a straight quote takes its direction from the character before it, and a single quote before
     * an ASCII digit is the apostrophe. A quote node of the other kind reads back as that kind.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function smart(array $node, string $path, Context $c, int $next): string
    {
        $source = self::str($node, 'source');
        if ($source !== '"' && $source !== "'") {
            return $source;
        }
        $prev = $this->before;
        $prevType = is_array($prev) ? self::str($prev, 'type') : '';
        if ($prevType === 'comment') {
            return $source;
        }
        // C2-I-104: the content of a node starts with nothing before it; behind another node the reader sees a word
        // character, a quote glyph, a no-break space or a line break.
        $before = match ($prevType) {
            '', 'soft_break', 'hard_break', 'non_breaking_space' => -1,
            'text' => $c->last,
            'smart_punctuation' => str_starts_with(self::str($prev, 'kind'), 'left_') ? 0x201C : 0x61,
            default => 0x61,
        };
        $opens = Chars::isSpace($before) || ($before >= 0 && $before < 0x80
            ? str_contains(Tables::SMART_QUOTE_OPENS_AFTER, chr($before))
            : $before >= 0x80 && mb_strpos(Tables::SMART_QUOTE_OPENS_AFTER, (string)mb_chr($before, 'UTF-8')) !== false);
        $kind = $source === '"'
            ? ($opens ? 'left_double_quote' : 'right_double_quote')
            : ($opens && ($next < 0x30 || $next > 0x39) ? 'left_single_quote' : 'right_single_quote');
        if ($kind !== self::str($node, 'kind')) {
            $this->w->lose($path, 'smart_punctuation', 'kind');
        }

        return $source;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function mention(array $node, string $path): string
    {
        if (isset($node['label'])) {
            $this->w->lose($path, 'mention', 'label');
        } elseif (($node['kind'] ?? 'user') !== 'user') {
            $this->w->lose($path, 'mention', 'kind');
        }

        return '@' . self::str($node, 'target');
    }

    /**
     * C2-W-116, C2-A-216: the target in angle brackets; the email form is its text.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function autolink(array $node, string $path): string
    {
        $href = self::str($node, 'href');
        if (!isset($node['text'])) {
            return '<' . $href . '>';
        }
        $text = self::str($node, 'text');
        if ($href !== 'mailto:' . $text) {
            $this->w->lose($path, 'autolink', 'text');
        }

        return str_starts_with($href, 'mailto:') ? '<' . substr($href, 7) . '>' : '<' . $href . '>';
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function xref(array $node, string $path): string
    {
        if (isset($node['display'])) {
            $this->w->lose($path, 'xref', 'display');
        }

        return '</#' . self::str($node, 'key') . '>';
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function abbreviation(array $node, string $path): string
    {
        $key = self::str($node, 'key');
        // C2-W-117: without a definition of its key the word reads back as text.
        if (!isset($this->w->abbreviations[$key])) {
            $this->w->lose($path, 'abbreviation', null);
        }

        return $key;
    }

    /**
     * C2-W-059: a braced comment, or a line comment at the end of its line.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function comment(array $node, string $path, Context $c): string
    {
        $value = $this->cellValue($node, $path, $c, self::str($node, 'value'));
        if (($node['form'] ?? '') === 'braced') {
            return $value === '' ? '{%%}' : '{% ' . $value . ' %}';
        }
        $kept = rtrim($value, " \t");
        if ($kept !== $value || str_contains($kept, "\n")) {
            $this->w->lose($path, 'comment', 'value');
        }
        // C2-I-023: a line comment starts at the start of a line or after whitespace.
        $lead = $c->last === -1 || $c->last === 10 || Chars::isSpace($c->last) ? '' : ' ';

        return $lead . ($kept === '' ? '%%' : '%% ' . str_replace("\n", ' ', $kept));
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function extension(array $node, string $path, Context $c, int $next): ?string
    {
        $name = self::str($node, 'name');
        if (!str_contains($name, '.')) {
            $c->last = 0x5B;

            return ':' . $name . $this->bracketed('[', $node + ['children' => []], $path, $c);
        }
        $source = is_array($node['source'] ?? null) ? $node['source'] : [];
        if (($source['form'] ?? '') === 'inline' && is_string($source['name'] ?? null) && is_string($source['body'] ?? null)) {
            $text = ':' . $source['name'] . '[' . $source['body'] . ']';
            // C2-X-023, C2-X-049
            if (ExtensionSource::readsBack($node, $text, $this->w->extensions)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function citations(array $node, string $path): string
    {
        $this->w->lose($path, 'citation_group', null);

        return self::str($node, 'raw');
    }
}
