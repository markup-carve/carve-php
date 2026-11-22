<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Node;

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
     * The nodes whose content stands between brackets, a barrier of the delimiter stack.
     *
     * @var array<string>
     */
    private const BARRIERS = ['link', 'image', 'span', 'inline_footnote', 'inline_extension', 'citation_group'];

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
     * The node written before the one being written in its list past braced comments, null at the start of the list.
     */
    private mixed $before = null;

    /**
     * The character written before the node being written past braced comments, -1 at the start of a line.
     */
    private int $visibleLast = -1;

    /**
     * The first character written behind the node being written past braced comments.
     */
    private int $visibleNext = -1;

    /**
     * The prefix and the value of the text node last written by `text`, for the run log of C2-W-146.
     */
    private int $textPrefix = 0;

    private string $textValue = '';

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
        if ($this->w->repairTrace !== null) {
            return $this->contentOnce($nodes, $path, $c, $next);
        }
        if ($c->flat !== null) {
            return $this->contentOnce($nodes, $path, $c, $next);
        }
        // C2-W-146: a pair across the text nodes of a run is tested on the whole content, which is written again
        // with the backslashes it asks for.
        $start = clone $c;
        $out = $this->contentOnce($nodes, $path, $c, $next);
        $forced = [];
        for ($round = 0; $round < 64; $round++) {
            $asked = Text::runPairOpeners($c->runLog);
            $new = false;
            foreach ($asked as $at => $offsets) {
                foreach ($offsets as $i => $_) {
                    $new = $new || !isset($forced[$at][$i]);
                    $forced[$at][$i] = true;
                }
            }
            if (!$new) {
                break;
            }
            $again = clone $start;
            $again->runPairEscapes = $forced;
            // The first write charged the reparse of its first line; the same line again is no new repair state.
            $again->writerState = null;
            $out = $this->contentOnce($nodes, $path, $again, $next);
            foreach (get_object_vars($again) as $name => $field) {
                $c->{$name} = $field;
            }
            $c->writerState = $start->writerState;
        }
        $c->runLog = [];

        return $out;
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function contentOnce(array $nodes, string $path, Context $c, int $next): string
    {
        if ($c->flat === null) {
            $c->flat = '';
            $c->labels = $this->w->labels;
            self::flatten($nodes, $path, $c);
        }
        $out = '';
        $count = count($nodes);
        $visible = $c->lineStart ? -1 : $c->last;
        $visibleNode = null;
        $redo = null;
        $parts = [];
        for ($at = 0; $at < $count; $at++) {
            $node = $nodes[$at];
            if (!is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            self::resetFollowingPipe($c, $type);
            $c->lineCommentBefore = $type === 'text' && self::followsLineComment($nodes, $at);
            $following = $nodes[$at + 1] ?? null;
            $this->before = $visibleNode;
            $this->visibleLast = $visible;
            $this->visibleNext = self::visibleNext($nodes, $at, $c, $next);
            $c->lineTail = $type === 'text' && is_array($following) ? self::spanLineTail($following) : null;
            self::setFollowingTextLine($c, $nodes, $at, $type);
            if ($c->inCell) {
                $this->loseGluedRun($nodes, $at, $path, $type);
            }
            $first = $at;
            if ($type === 'code' && BraceBlock::of($node) === '' && is_array($following) && ($following['type'] ?? '') === 'code') {
                [$part, $at] = $this->codeRun($nodes, $at, $path, $c);
            } else {
                $part = $this->listed($nodes, $at, $path, $c, $next, $out, $redo);
            }
            $parts[] = [strlen($out), $first, $at];
            $out .= $part;
            self::advanceFollowingPipe($c, $node, $type);
            if (!self::isBracedComment($node)) {
                $visible = $c->lineStart ? -1 : $c->last;
                $visibleNode = $node;
            }
        }
        if (!$c->inCell && $this->w->repairTrace === null) {
            $this->readBack($nodes, $parts, $out, $path);
        }

        return $out;
    }

    /**
     * @var array<string, true>
     */
    private const VERBATIM = ['code' => true, 'math' => true, 'raw_inline' => true, 'literal_inline' => true];

    /**
     * C2-W-112, C2-W-117, C2-W-124: a backtick run glued to the closer of a span joins it, so the span reads on to the
     * next run of its own width, which may lie past several nodes (carve#6341). The content is read back from the
     * first glued closer on; a verbatim span that ends elsewhere than its spelling loses its value, or the node when it
     * reads as another type, and every node that starts inside such a span is lost.
     *
     * @param array<mixed> $nodes
     * @param array<array{0: int, 1: int, 2: int}> $parts Each written part: its offset in `$out`, its first and last node.
     * @param string $out
     * @param string $path
     *
     * @return void
     */
    private function readBack(array $nodes, array $parts, string $out, string $path): void
    {
        $glued = self::gluedPart($parts, $out);
        if ($glued === null) {
            return;
        }
        $base = $parts[$glued][0];
        // A letter and a space first, so a long backtick run opens no fence and the spans stand mid-line.
        $read = Carve::parse('x ' . substr($out, $base) . "\n")->children ?? [];
        if (count($read) !== 1 || $read[0]->type !== 'paragraph') {
            // Later content opens another block, so the run is predicted from the spans alone.
            foreach ($parts as [, $first]) {
                $this->loseGluedRun($nodes, $first, $path, self::str((array)$nodes[$first], 'type'));
            }

            return;
        }
        $children = $read[0]->children ?? [];
        $c = 0;
        $lead = '';
        $count = count($parts);
        for ($k = $glued; $k < $count; $k++) {
            [$start, $first, $last] = $parts[$k];
            while (isset($children[$c]) && $base + $children[$c]->end - 2 <= $start) {
                $c++;
                $lead = '';
            }
            $type = self::str((array)$nodes[$first], 'type');
            $child = $children[$c] ?? null;
            $field = $child === null ? (isset(self::VERBATIM[$type]) ? null : false) : self::taken(
                $type,
                $child,
                $start - $base + 2,
                ($parts[$k + 1][0] ?? strlen($out)) - $base + 2,
                $lead,
            );
            if ($field === false) {
                continue;
            }
            $this->w->lose($path . '/' . $first, $type, $field);
            for ($member = $first + 1; $member <= $last; $member++) {
                $this->w->lose($path . '/' . $member, self::str((array)$nodes[$member], 'type'), null);
            }
        }
    }

    /**
     * The first part whose closing backtick the part behind it joins.
     *
     * @param array<array{0: int, 1: int, 2: int}> $parts
     * @param string $out
     *
     * @return int|null
     */
    private static function gluedPart(array $parts, string $out): ?int
    {
        foreach ($parts as $k => [$start]) {
            if ($k > 0 && ($out[$start] ?? '') === '`' && $out[$start - 1] === '`') {
                return $k - 1;
            }
        }

        return null;
    }

    /**
     * What a written part from `$start` to `$end` loses when the reader puts its start in `$child`: false for nothing,
     * else the field it loses, or null for the node. `$lead` is the type of the part the child starts with.
     *
     * @param string $type
     * @param \MarkupCarve\Carve\Node $child
     * @param int $start
     * @param int $end
     * @param string $lead
     *
     * @return string|false|null
     */
    private static function taken(string $type, Node $child, int $start, int $end, string &$lead): string|false|null
    {
        $read = $child->start <= $start && isset(self::VERBATIM[$child->type]) ? $child->type : null;
        if ($read !== null && $child->start === $start) {
            $lead = $type;
            if ($child->end === $end && $read === $type) {
                return false;
            }

            return $read === $type ? 'value' : null;
        }
        if ($read !== null) {
            // A code run that ends in a raw span reads as that raw span.
            if ($read === 'raw_inline' && $type === 'raw_inline' && $child->end === $end && $lead === 'code') {
                return false;
            }

            return $type === 'text' ? 'value' : null;
        }

        return isset(self::VERBATIM[$type]) ? null : false;
    }

    private static function resetFollowingPipe(Context $c, string $type): void
    {
        if ($type !== 'text' && !in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)) {
            $c->escapeFollowingPipeAt = -1;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param array<mixed> $node
     * @param string $type
     *
     * @return void
     */
    private static function advanceFollowingPipe(Context $c, array $node, string $type): void
    {
        if ($type !== 'text') {
            return;
        }
        $length = strlen(self::str($node, 'value'));
        $c->escapeFollowingPipeAt = $c->escapeFollowingPipeAt >= $length
            ? $c->escapeFollowingPipeAt - $length : -1;
    }

    /**
     * The line up to the first break of a following verbatim span, for the opener test of text before it.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function spanLineTail(array $node): ?string
    {
        $type = self::str($node, 'type');
        if (!in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)) {
            return null;
        }
        $value = self::str($node, 'value');
        if (!str_contains($value, "\n")) {
            return null;
        }
        $prefix = match ($type) {
            'math' => ($node['display'] ?? false) === true ? '$$' : '$',
            'literal_inline' => '!',
            default => '',
        };
        $spelled = $prefix . self::codeSpan($value);

        return substr($spelled, 0, (int)strpos($spelled, "\n"));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $type
     *
     * @return void
     */
    private static function setFollowingTextLine(Context $c, array $nodes, int $at, string $type): void
    {
        $c->lineAfterSpan = '';
        $c->lineAfterSpanEnds = false;
        $c->lineAfterSpanPipeSource = -1;
        $c->lineAfterSpanPipeText = -1;
        if (!self::multilineSpan($nodes[$at] ?? null, $type)) {
            return;
        }
        $c->lineAfterSpanEnds = true;
        $textOffset = 0;
        $probe = clone $c;
        $probe->lineStart = false;
        $probe->last = 0x60;
        $probe->here = -1;
        $probe->writerState = null;
        $probe->escapeFollowingPipeAt = -1;
        for ($i = $at + 1, $n = count($nodes); $i < $n; $i++) {
            $node = $nodes[$i];
            $nextType = is_array($node) ? self::str($node, 'type') : '';
            $spanSpelling = is_array($node) ? self::simpleSpanSpelling($node) : null;
            if (in_array($nextType, ['soft_break', 'hard_break'], true)) {
                break;
            }
            if ($nextType === 'text') {
                $value = self::str($node, 'value');
                $break = strpos($value, "\n");
                $fragment = $break === false ? $value : substr($value, 0, $break);
                $spelled = Text::escape($fragment, $probe, $break === false ? -1 : 10);
                $pipe = strrpos($fragment, '|');
                if ($pipe !== false) {
                    $c->lineAfterSpanPipeSource = strlen($c->lineAfterSpan) + (int)strrpos($spelled, '|');
                    $c->lineAfterSpanPipeText = $textOffset + $pipe;
                }
                $textOffset += strlen($value);
            } elseif ($spanSpelling !== null) {
                $spelled = $spanSpelling;
                $break = strpos($spelled, "\n");
                if ($break !== false) {
                    $spelled = substr($spelled, 0, $break);
                }
            } else {
                $c->lineAfterSpanEnds = false;

                break;
            }
            $c->lineAfterSpan .= $spelled;
            if ($spelled !== '') {
                $probe->last = Chars::before($spelled, strlen($spelled));
            }
            if ($break !== false) {
                break;
            }
        }
    }

    private static function multilineSpan(mixed $node, string $type): bool
    {
        return is_array($node) && in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)
            && str_contains(self::str($node, 'value'), "\n");
    }

    /**
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function simpleSpanSpelling(array $node): ?string
    {
        $type = self::str($node, 'type');
        if (!in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)) {
            return null;
        }
        $prefix = match ($type) {
            'math' => ($node['display'] ?? false) === true ? '$$' : '$',
            'literal_inline' => '!',
            default => '',
        };

        return $prefix . self::codeSpan(self::str($node, 'value'))
            . ($type === 'raw_inline' ? '{=' . self::str($node, 'format') . '}' : '');
    }

    /**
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return bool
     */
    private static function followsLineComment(array $nodes, int $at): bool
    {
        $prior = $nodes[$at - 2] ?? null;
        $break = $nodes[$at - 1] ?? null;

        return is_array($break) && ($break['type'] ?? '') === 'soft_break'
            && is_array($prior) && ($prior['type'] ?? '') === 'comment' && ($prior['form'] ?? '') === 'line';
    }

    /**
     * Writes the node at `$at` of a list. C2-W-069: a text that ends in `{` before emphasis or strong reads the first
     * character of the form the node takes, so it is written again once the node comes out braced.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param string $out What the list wrote so far.
     * @param array{0: int, 1: int, 2: \MarkupCarve\Carve\Writer\Context}|null $redo Where the text before stands in
     *   `$out`, and the context it was written in.
     *
     * @return string
     */
    private function listed(array $nodes, int $at, string $path, Context $c, int $next, string &$out, ?array &$redo): string
    {
        $node = (array)$nodes[$at];
        $following = $nodes[$at + 1] ?? null;
        $after = is_array($following) ? self::first($following, $c) : $next;
        $retry = self::str($node, 'type') === 'text' && is_array($following) && isset(self::PAIRED[self::str($following, 'type')])
            && str_ends_with(self::str($node, 'value'), '{');
        $snapshot = $retry ? clone $c : null;
        $part = $this->node($node, $path . '/' . $at, $c, $after, $nodes[$at - 1] ?? null);
        if ($redo !== null && str_starts_with($part, '{')) {
            [$from, $length, $text] = $redo;
            // The replay revisits the same first-line lookahead with the next node now known.
            $text->writerState = null;
            $again = $this->node((array)$nodes[$at - 1], $path . '/' . ($at - 1), $text, 0x7B, $nodes[$at - 2] ?? null);
            $out = substr_replace($out, $again, $from, $length);
        }
        $redo = $snapshot === null ? null : [strlen($out), strlen($part), $snapshot];

        return $part;
    }

    /**
     * C2-I-026: a braced comment counts as nothing for its neighbors.
     *
     * @param mixed $node
     *
     * @return bool
     */
    private static function isBracedComment(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? '') === 'comment' && ($node['form'] ?? '') === 'braced';
    }

    /**
     * The first character written behind the node at `$at` past braced comments, `$next` at the end of the list.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return int
     */
    private static function visibleNext(array $nodes, int $at, Context $c, int $next): int
    {
        $count = count($nodes);
        for ($k = $at + 1; $k < $count; $k++) {
            if (is_array($nodes[$k]) && !self::isBracedComment($nodes[$k])) {
                return self::first($nodes[$k], $c);
            }
        }

        return $next;
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
     * The text a node holds outside brackets and outside nodes of `$mark`, each text closed by `\0`: where its closers of
     * `$mark` are written bare (C2-I-055).
     *
     * @param array<mixed> $node
     * @param string $mark
     *
     * @return string
     */
    public static function openText(array $node, string $mark): string
    {
        $type = self::str($node, 'type');
        if ($type === 'text') {
            return self::str($node, 'value') . "\0";
        }
        if (in_array($type, self::BARRIERS, true) || (self::BRACED[$type] ?? self::PAIRED[$type] ?? '') === $mark) {
            return '';
        }
        $out = '';
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $out .= is_array($child) ? self::openText($child, $mark) : '';
        }

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
     * C2-W-112, C2-W-117, C2-W-124: a backtick run directly behind a code or math span with no attribute block joins its
     * closer. When the span behind opens with as many backticks as that closer, its own closer ends the joined span, so
     * the run goes on through every such span and ends at the first raw span or attribute block. It reads back as one
     * node: a math leader keeps its type and loses its value, a code run that ends in a raw span reads as that raw span,
     * and every other node of the run is lost. Only a table cell, whose pipes keep it from being read back on its own,
     * predicts the run here; other content is read back by `readBack`.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param string $type
     *
     * @return void
     */
    private function loseGluedRun(array $nodes, int $at, string $path, string $type): void
    {
        $node = $nodes[$at] ?? null;
        if (($type !== 'code' && $type !== 'math') || !is_array($node) || BraceBlock::of($node) !== '') {
            return;
        }
        $spans = self::writtenSpans($nodes, $at);
        if (count($spans) < 2) {
            return;
        }
        [$leader] = $spans;
        if ($type === 'code') {
            if (count($leader['nodes']) === 1 || $spans[1]['width'] === $leader['width']) {
                // The leader's code spans are reported by `codeRun`; the raw span behind keeps the joined value.
                $this->w->lose($path . '/' . $at, 'code', null);
            }

            return;
        }
        $this->w->lose($path . '/' . $at, 'math', 'value');
        $width = $leader['width'];
        foreach (array_slice($spans, 1) as $k => $span) {
            if ($k > 0 && $span['width'] !== $width) {
                break;
            }
            foreach ($span['nodes'] as $index => $behind) {
                $this->w->lose($path . '/' . $index, $behind, null);
            }
            if ($span['closed'] || ($k === 0 && $span['width'] !== $width)) {
                break;
            }
            $width = $span['width'];
        }
    }

    /**
     * The spans the writer spells from `$at` on while each opens directly behind the closer before it: consecutive code
     * spans make one span, as `codeRun` writes them. Each has its fence width, its nodes and whether an attribute block
     * or a raw format ends it.
     *
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return list<array{width: int, nodes: array<int, string>, closed: bool}>
     */
    private static function writtenSpans(array $nodes, int $at): array
    {
        $spans = [];
        $end = $at;
        while (is_array($nodes[$end] ?? null)) {
            $type = self::str($nodes[$end], 'type');
            $allowed = $end === $at ? ['code', 'math'] : ['code', 'raw_inline'];
            if (!in_array($type, $allowed, true)) {
                break;
            }
            $value = '';
            $members = [];
            do {
                $value .= self::str($nodes[$end], 'value');
                $members[$end] = $type;
                $closed = $type === 'raw_inline' || BraceBlock::of($nodes[$end]) !== '';
                $end++;
            } while ($type === 'code' && !$closed && is_array($nodes[$end] ?? null) && self::str($nodes[$end], 'type') === 'code');
            $spans[] = ['width' => self::fenceWidth($value), 'nodes' => $members, 'closed' => $closed];
            if ($closed) {
                break;
            }
        }

        return $spans;
    }

    /**
     * The length of the backtick fence `codeSpan` writes around `$value`.
     *
     * @param string $value
     *
     * @return int
     */
    private static function fenceWidth(string $value): int
    {
        return strspn(self::codeSpan($value), '`');
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
            $this->loseTextFields($node, $path);
            $points = $c->inCell ? ($this->w->wrapPoints[$path] ?? null) : null;
            $c->here = $points === null ? $c->flatAt[$path] ?? -1 : -1;
            $c->textPath = $path;
            $out = $points === null ? $this->text($node, $c, $next, $before) : $this->wrapped($node, $points, $c, $next);
            $c->here = -1;
            if ($points === null) {
                $c->runLog[] = [

                    'path' => $path,
                    'out' => $out,
                    'prefix' => $this->textPrefix,
                    'escaped' => $c->escapedAt,
                    'value' => $this->textValue,
                ];
            }
            // C2-W-016: a CR at the end of a line is part of the line ending.
            if (str_ends_with($out, "\r") && ($next === 10 || $next === -1)) {
                $this->w->lose($path, 'text', 'value');
            }
            $this->moved($c, $out);

            return $out;
        }
        $out = $this->own($node, $type, $path, $c, $next);
        if ($type === 'soft_break') {
            $c->runLog[] = ['path' => $path, 'out' => "\n", 'prefix' => 1, 'escaped' => [], 'value' => ''];
        }
        if ($out === null) {
            $this->w->lose($path, $type, null);
            $out = $this->children($node, $path, $c, $next, 'fallback');
        } elseif (!in_array($type, ['soft_break', 'hard_break', 'comment'], true)) {
            // C2-W-076: the attribute block is glued to its host. C2-I-040: a span takes the block with no part.
            $block = BraceBlock::of($node, ['pipes' => $c->inCell]);
            $out .= $type === 'span' && $block === '' ? '{}' : $block;
        }
        $this->moved($c, $out);

        return $this->tracedNode($out, $path, $type);
    }

    private function tracedNode(string $out, string $path, string $type): string
    {
        return $this->w->repairTrace?->inlineNode($out, $path, $type) ?? $out;
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
     * A text node has no carrier for these fields. Check every node, even behind another loss.
     *
     * @param array<string, mixed> $node
     * @param string $path
     */
    private function loseTextFields(array $node, string $path): void
    {
        foreach (['dir', 'metadata'] as $field) {
            if (array_key_exists($field, $node)) {
                $this->w->lose($path, 'text', $field);
            }
        }
    }

    private function reportLeadingValueLoss(string $value, Context $c): void
    {
        if (
            $c->lineStart && $c->last === -1 && !$c->inLineBlock && !$c->inCell
            && strspn($value, " \t") > 0 && trim($value, " \t") !== ''
        ) {
            $this->w->lose($c->textPath, 'text', 'value');
        }
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
        $this->reportLeadingValueLoss($value, $c);
        $this->loseLineFeed($value, $c);
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

        $this->textPrefix = strlen($prefix);
        $this->textValue = $value;

        return $prefix . Text::escapePairs($value, $c, $next);
    }

    /**
     * C2-W-117: outside a cell a line feed in text reads back as a soft break, so the value does not survive.
     */
    private function loseLineFeed(string $value, Context $c): void
    {
        if (!$c->inCell && str_contains($value, "\n")) {
            $this->w->lose($c->textPath, 'text', 'value');
        }
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
            'symbol' => $this->sigil($type, $path, $next, ':' . self::str($node, 'name') . ':'),
            'tag' => $this->sigil($type, $path, $next, '#' . self::str($node, 'name')),
            'non_breaking_space' => $this->noBreakSpace($path, $c, $next),
            'smart_punctuation' => $this->smart($node, $path),
            // C2-W-116: the mark of a caption number (C2-B-150); its number is a resolution result.
            'caption_number' => '#',
            'mention' => $this->sigil($type, $path, $next, $this->mention($node, $path)),
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
        $marks = $c->marks;
        $c->open[$type] = ($c->open[$type] ?? 0) + 1;
        $c->marks .= $mark;
        $spanTexts = $c->spanTexts;
        self::noteSpanTexts($children, $path . '/children', $mark, $c);
        $inner = $this->content($children, $path . '/children', $c, ord($mark));
        $c->spanTexts = $spanTexts;
        $c->open = $open;
        $c->marks = $marks;
        $visibleInner = $this->w->repairTrace?->decode($inner)['source'] ?? $inner;
        $bare = !isset($open[$type]) && !self::holds($children, $type, $mark) && self::bareFits($visibleInner, $mark, $prev, $next);

        return $bare ? $mark . $inner . $mark : '{' . $mark . $inner . $mark . '}';
    }

    /**
     * The facts the escape of `{` reads in the text children of a span, which have no place in the flattened leaf.
     *
     * @param array<mixed> $children
     * @param string $path
     * @param string $mark
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return void
     */
    private static function noteSpanTexts(array $children, string $path, string $mark, Context $c): void
    {
        $last = count($children) - 1;
        foreach ($children as $k => $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'text') {
                $c->spanTexts[$path . '/' . $k] = [$mark, $k < $last, $k > 0];
            }
        }
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
        $marks = $c->marks;
        $c->marks .= $mark;
        $spanTexts = $c->spanTexts;
        self::noteSpanTexts($children, $path . '/children', $mark, $c);
        $inner = $this->content($children, $path . '/children', $c, ord($mark));
        $c->spanTexts = $spanTexts;
        $c->marks = $marks;

        return '{' . $mark . $inner . $mark . '}';
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
        $inner = $this->inside($children, $path . '/children', $c);
        // C2-W-125 case five: a caret at the start of span text would read as a footnote reference.
        if ($open === '[' && str_starts_with($this->w->repairTrace?->decode($inner)['source'] ?? $inner, '^')) {
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
        $outer = clone $c;
        $bracket = $c->inBracket;
        $marks = $c->marks;
        $pairs = $c->bracketPairs;
        $c->inBracket = true;
        $c->bracketPairs = self::pairedBrackets($children, $path);
        // A bracket is a barrier of the delimiter stack: no closer inside it reaches a node outside.
        $c->marks = '';
        $c->last = 0x5B;
        $c->lineStart = false;
        // A bracketed list is its own escape leaf. Its text offsets cannot use the surrounding list's flattened text.
        $c->flat = null;
        $c->flatAt = [];
        $c->flatTypes = [];
        $c->flatFirst = [];
        $c->flatAtoms = [];
        $c->flatBraces = [];
        $c->flatNodes = [];
        $c->closers = null;
        $c->partners = null;
        $inner = $this->content($children, $path, $c, 0x5D);
        $c->flat = $outer->flat;
        $c->flatAt = $outer->flatAt;
        $c->flatTypes = $outer->flatTypes;
        $c->flatFirst = $outer->flatFirst;
        $c->flatAtoms = $outer->flatAtoms;
        $c->flatBraces = $outer->flatBraces;
        $c->flatNodes = $outer->flatNodes;
        $c->closers = $outer->closers;
        $c->partners = $outer->partners;
        $c->inBracket = $bracket;
        $c->bracketPairs = $pairs;
        $c->marks = $marks;

        return $inner;
    }

    /**
     * Pair brackets in text across emphasis and other unbracketed nodes. Nested bracketed constructs own their pairs.
     *
     * @param array<mixed> $nodes
     * @param string $path
     *
     * @return array<string, array<int, true>>
     */
    private static function pairedBrackets(array $nodes, string $path): array
    {
        $open = [];
        $paired = [];
        $walk = static function (array $list, string $base) use (&$walk, &$open, &$paired): void {
            foreach ($list as $at => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = $node['type'] ?? '';
                $key = $base . '/' . $at;
                if ($type === 'text') {
                    $value = is_string($node['value'] ?? null) ? $node['value'] : '';
                    for ($i = 0, $n = strlen($value); $i < $n; $i++) {
                        if ($value[$i] === '[') {
                            $open[] = [$key, $i];
                        } elseif ($value[$i] === ']' && $open !== []) {
                            [$other, $offset] = array_pop($open);
                            $paired[$other][$offset] = true;
                            $paired[$key][$i] = true;
                        }
                    }
                } elseif ($type === 'link') {
                    // A nested link's own bracketed spelling ends any pair that was
                    // still open in the containing label.
                    $open = [];
                } elseif (!in_array($type, self::BARRIERS, true) && is_array($node['children'] ?? null)) {
                    $walk($node['children'], $key . '/children');
                }
            }
        };
        $walk($nodes, $path);

        return $paired;
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
        if (str_starts_with($this->w->repairTrace?->decode($inner)['source'] ?? $inner, '^')) {
            $inner = '\\' . $inner;
        }

        if (
            in_array($node['refForm'] ?? '', ['collapsed', 'shortcut'], true)
            && is_string($node['key'] ?? null) && Chars::fold($inner) !== $node['key']
        ) {
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
        if (array_key_exists('decorative', $node)) {
            $this->w->lose($path, 'image', 'decorative');
        }
        $alt = self::str($node, 'alt');
        $spelled = AltSource::spell($alt);
        $spelled = $c->inCell ? $this->cellPipes($spelled, $path) : $spelled;

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
            if ($before === ' ' && self::backslashesBefore($value, $at - 1) % 2 === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts only the run ending at `$end`, so a value with many line feeds stays linear.
     *
     * @param string $value
     * @param int $end
     *
     * @return int
     */
    private static function backslashesBefore(string $value, int $end): int
    {
        $at = $end;
        while ($at > 0 && $value[$at - 1] === '\\') {
            $at--;
        }

        return $end - $at;
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
        $value = $this->cellValue($node, $path, $c, self::str($node, 'value'), true);
        $spelling = $prefix . self::codeSpan($value);
        if ($c->inLineBlock && !$c->inCell && str_contains($value, "\n")) {
            return $spelling;
        }
        $break = strpos($spelling, "\n");
        $firstLine = $break === false ? $spelling : substr($spelling, 0, $break);
        if ($c->lineStart && strspn($firstLine, '`') >= 3 && Text::opensBlock($firstLine, $c, true)) {
            $this->w->lose($path, self::str($node, 'type'), 'value');
        }
        if ($value === '') {
            // C2-W-116: an empty run pair reads back as text.
            $this->w->lose($path, self::str($node, 'type'), null);
        } elseif ((str_starts_with($value, "\n") && str_ends_with($value, '`')) || self::losesLineEdges($value)) {
            $this->w->lose($path, self::str($node, 'type'), 'value');
        }

        return $spelling;
    }

    /**
     * C2-W-067: no form crosses a row of a pipe table, so a line feed in a cell is written as a space and reported.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $value
     * @param bool $span
     *
     * @return string
     */
    private function cellValue(array $node, string $path, Context $c, string $value, bool $span = false): string
    {
        if (!str_contains($value, "\n")) {
            return $value;
        }
        if (!$c->inCell) {
            if ($c->inLineBlock) {
                // C2-W-124: the line ending ends the line, so the node reads back as text.
                $this->w->lose($path, self::str($node, 'type'), null);

                return $value;
            }
            if (str_contains($value, "\r\n") || (!$span && self::losesLineEdges($value))) {
                // The reader drops the CR of a line ending and, in a comment, the spaces and tabs around it.
                $this->w->lose($path, self::str($node, 'type'), 'value');
            }
            $lines = array_slice(explode("\n", $span ? self::codeSpan($value) : $value), 1);
            $last = count($lines) - 1;
            foreach ($lines as $at => $line) {
                if ($span && $at === $last) {
                    if (!self::spellableFinalSpanLine($line, $c)) {
                        $this->w->lose($path, self::str($node, 'type'), 'value');

                        break;
                    }

                    continue;
                }
                if (!$span && $at === $last && $line === '') {
                    $spelled = self::codeSpan($value);
                    $line = substr($spelled, (int)strrpos($spelled, "\n") + 1);
                    $line = str_starts_with($line, ' ') ? '' : $line;
                }
                if (Text::opensBlock($line, $span ? $c : null)) {
                    $this->w->lose($path, self::str($node, 'type'), 'value');

                    break;
                }
            }

            return $value;
        }
        $this->w->lose($path, self::str($node, 'type'), 'value');

        return str_replace("\n", ' ', $value);
    }

    private static function spellableFinalSpanLine(string $line, Context $c): bool
    {
        // C2-W-151: spaces and tabs that end the line after the span are dropped from the text, not lost by the span.
        if (str_starts_with($line, ' ')) {
            return false;
        }
        if (!$c->lineAfterSpanEnds && str_starts_with(ltrim($line, " \t"), '|')) {
            return false;
        }
        $emittedLine = $line . $c->lineAfterSpan;
        if (!Text::opensBlock($emittedLine, $c)) {
            return true;
        }
        $pipe = $c->lineAfterSpanPipeSource;
        if (
            $pipe < 0 || Text::opensBlock($line . substr($c->lineAfterSpan, 0, $pipe) . '\\'
                . substr($c->lineAfterSpan, $pipe), $c)
        ) {
            return false;
        }
        $c->escapeFollowingPipeAt = $c->lineAfterSpanPipeText;

        return true;
    }

    /**
     * C2-I-090: a sigil after a letter or number starts no form, so the node reads back as text. C2-I-092: a letter or
     * digit behind a mention or a tag lengthens its name, and no backslash keeps it out (C2-W-125 case six).
     *
     * @param string $type
     * @param string $path
     * @param int $next
     * @param string $out
     *
     * @return string
     */
    private function sigil(string $type, string $path, int $next, string $out): string
    {
        if (Chars::isWord($this->visibleLast)) {
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
     *
     * @return string
     */
    private function smart(array $node, string $path): string
    {
        $source = self::str($node, 'source');
        if ($source !== '"' && $source !== "'") {
            return $source;
        }
        $prev = $this->before;
        $prevType = is_array($prev) ? self::str($prev, 'type') : '';
        // C2-I-104: the content of a node starts with nothing before it; behind another node the reader sees a word
        // character, a quote glyph, a no-break space or a line break.
        $before = match ($prevType) {
            '', 'soft_break', 'hard_break', 'non_breaking_space' => -1,
            'text' => $this->visibleLast,
            'smart_punctuation' => in_array(self::str($prev, 'kind'), ['left_single_quote', 'left_double_quote'], true)
                ? 0x201C : 0x61,
            default => 0x61,
        };
        $opens = Chars::isSpace($before) || ($before >= 0 && $before < 0x80
            ? str_contains(Tables::SMART_QUOTE_OPENS_AFTER, chr($before))
            : $before >= 0x80 && mb_strpos(Tables::SMART_QUOTE_OPENS_AFTER, (string)mb_chr($before, 'UTF-8')) !== false);
        $kind = $source === '"'
            ? ($opens ? 'left_double_quote' : 'right_double_quote')
            : ($opens && ($this->visibleNext < 0x30 || $this->visibleNext > 0x39) ? 'left_single_quote' : 'right_single_quote');
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
        $braced = ($node['form'] ?? '') === 'braced';
        // A line comment outside a cell writes its line feeds as spaces below, so it reads back as a comment.
        $value = $braced || $c->inCell ? $this->cellValue($node, $path, $c, self::str($node, 'value')) : self::str($node, 'value');
        if ($braced) {
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
            if (ExtensionSource::readsBack($node, $text, $this->w->extensions, $this->w, $path)) {
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
