<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\GroupWrite;
use MarkupCarve\Carve\Writer\Inline;
use MarkupCarve\Carve\Writer\LinkFallback;
use MarkupCarve\Carve\Writer\Text;
use MarkupCarve\Carve\Writer\Writer;

/**
 * A `GroupWriter` over the PHP block writer: the group is written as a document of its own, with the escapes of the
 * state keyed by the paths the group's nodes take there. The paragraphs' places come from the marked write.
 */
final class TreeGroupWriter implements GroupWriter
{
    /**
     * @var int
     */
    private const GLUE_MEMO_SIZE = 4096;

    /**
     * @var array<string, array<string, true>> The glued pairs of each write, by a hash of its inputs.
     */
    private static array $glueMemo = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options = [])
    {
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    public function write(WriterState $w, array $group): void
    {
        self::placeEscapes($w, $group);
        [$tree, $escapes] = self::document($w, $group);
        if (self::escapeLoneLinkBrackets($w, $group, $tree)) {
            [$tree, $escapes] = self::document($w, $group);
        }
        // A lone paragraph with no field beside its content starts the source, so its place needs no marked write.
        $roots = is_array($tree['children']) ? $tree['children'] : [];
        $plain = count($group) === 1 && $group[0]->type === 'paragraph' && is_array($roots[0] ?? null)
            && array_diff(array_keys($roots[0]), ['type', 'pos', 'children']) === [];
        $key = md5(serialize([$tree, $escapes, $this->options]));
        GroupWrite::start();
        try {
            $candidate = $plain ? null : Writer::bareCandidate($tree, null, $this->options, $escapes);
            $source = $candidate['source'] ?? Writer::bareSource($tree, null, $this->options, $escapes);
        } finally {
            $glued = GroupWrite::take();
        }
        $glued = self::rememberGlue($key, $glued);
        if ($glued !== []) {
            self::glue($w, $group, $glued);
        }
        $w->lines = explode("\n", str_ends_with($source, "\n") ? substr($source, 0, -1) : $source);
        $w->starts = [];
        if ($candidate === null) {
            $w->starts[] = [$group[0], 0];

            return;
        }
        $lineStarts = [0];
        for ($at = strpos($source, "\n"); $at !== false; $at = strpos($source, "\n", $at + 1)) {
            $lineStarts[] = $at + 1;
        }
        foreach ($group as $at => $node) {
            self::notePlaces($w, $node, "/children/{$at}", $candidate['ranges'], $lineStarts);
        }
    }

    /**
     * The escapes carve-js `placeEscapes` puts in the writer state while it writes, placed before the write so the
     * rounds compare against them: the leading caret of link or span text (C2-W-125 case five, `escapeLeadingCaret`),
     * the doubled backslash that ends a text (C2-W-097, `doubleLastBackslash`), a backtick glued behind code
     * (`escapeGluedBacktick`), the open braces before the closer of a line comment (`braceBeforeCommentCloser`), the `]`
     * behind a pair that holds a text `^[` (`spanClosingBracket`) and the `{` that ends a text beside a bare pair
     * (`braceBesidePair`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function placeEscapes(WriterState $w, array $group): void
    {
        // Each list with the flags carve-js writes it with, and whether it is the content of a pair (AT_PAIRED_END).
        $pending = array_map(static fn (Node $node): array => [$node, 0], $group);
        while (($item = array_pop($pending)) !== null) {
            [$node, $flags] = $item;
            $paired = $node->type === 'emphasis' || $node->type === 'strong';
            $flags |= match ($node->type) {
                'emphasis' => Spell::IN_EMPHASIS,
                'strong' => Spell::IN_STRONG,
                'link' => Spell::IN_BRACKET,
                'inline_footnote' => Spell::IN_NOTE,
                default => 0,
            };
            $children = $node->children();
            $count = count($children);
            foreach ($children as $at => $child) {
                $before = $children[$at - 1] ?? null;
                if (($flags & Spell::IN_NOTE) === 0 && self::caretLeads($w, $child, $before)) {
                    self::placeEscape($w, $child->children()[0], 0);
                }
                if ($child->type === 'text') {
                    $next = $children[$at + 1] ?? null;
                    $after = $children[$at + 2] ?? null;
                    self::doubleLastBackslash($w, $child, $next, $flags);
                    self::placeTextEscapes($w, $child, $before, $next, $after, $flags);
                    self::braceBesidePair($w, $child, $next, $after, $paired && $at === $count - 1, $flags);
                }
                $pending[] = [$child, $flags];
            }
        }
    }

    /**
     * C2-W-097: a backslash that ends a text is written as two where what follows it is ASCII punctuation, a space or
     * the end of a line: the end of a list of inlines is the closing mark of its node or the end of the content
     * (carve-js `doubleLastBackslash`).
     */
    private static function doubleLastBackslash(WriterState $w, Node $text, ?Node $next, int $flags): void
    {
        $value = $text->value ?? '';
        $last = strlen($value) - 1;
        if ($last < 0 || $value[$last] !== '\\') {
            return;
        }
        $escapes = $w->escapesOf($text);
        if ($escapes !== [] && $escapes[count($escapes) - 1] === $last) {
            return;
        }
        if ($next !== null && !$next->isBreak()) {
            $spelled = $w->spell->inline($w, $next, $flags & ~Spell::IN_NOTE);
            if ($spelled !== '' && $spelled[0] !== ' ' && !Compare::isAsciiPunctuation($spelled[0])) {
                return;
            }
        }
        self::placeEscape($w, $text, $last);
    }

    private static function placeTextEscapes(WriterState $w, Node $text, ?Node $before, ?Node $next, ?Node $after, int $flags): void
    {
        $value = $text->value ?? '';
        $code = in_array($before?->type, ['code', 'literal_inline', 'math'], true) && $before->field('attrs') === null;
        if ($code && str_starts_with($value, '`')) {
            self::placeEscape($w, $text, 0);
        }
        self::braceBeforeCommentCloser($w, $text, $next, $after, $flags);
        $closing = strpos($value, ']');
        if ($closing === false || ($before?->type !== 'strong' && $before?->type !== 'emphasis')) {
            return;
        }
        foreach ($before->children() as $child) {
            if ($child->type === 'text' && preg_match('/^\^\[[^\]]*\[/', $child->value ?? '') === 1) {
                self::placeEscape($w, $text, $closing);

                return;
            }
        }
    }

    /**
     * The braces of a text that open a pair before the closer of a note or link which a line comment behind the text
     * would take (carve-js `braceBeforeCommentCloser`).
     */
    private static function braceBeforeCommentCloser(WriterState $w, Node $text, ?Node $comment, ?Node $after, int $flags): void
    {
        if (
            $comment?->type !== 'comment' || $comment->field('form') !== 'line' || $after !== null
            || ($flags & (Spell::IN_NOTE | Spell::IN_BRACKET)) === 0
        ) {
            return;
        }
        foreach (self::unclosedBraces($text->value ?? '') as $at) {
            self::placeEscape($w, $text, $at);
        }
    }

    /**
     * The offsets of the braced openers `{*`, `{/`, `{-`, `{=` of `value` that no closer of the same mark ends.
     *
     * @return list<int>
     */
    private static function unclosedBraces(string $value): array
    {
        $open = [];
        $length = strlen($value);
        for ($at = 0; $at + 1 < $length; $at++) {
            if ($value[$at] === '\\' && Compare::isAsciiPunctuation($value[$at + 1])) {
                $at++;

                continue;
            }
            if ($value[$at] === '{' && str_contains('*/-=', $value[$at + 1])) {
                $open[] = [$value[$at + 1], $at];
            } elseif ($value[$at + 1] === '}' && $open !== [] && $open[count($open) - 1][0] === $value[$at]) {
                array_pop($open);
            }
        }

        return array_map(static fn (array $entry): int => $entry[1], $open);
    }

    /**
     * C2-W-069: a `{` that ends a text before a bare pair, or at the end of the content of a pair, would open a braced
     * pair with its mark (carve-js `braceBesidePair`).
     */
    private static function braceBesidePair(WriterState $w, Node $text, ?Node $next, ?Node $after, bool $pairedEnd, int $flags): void
    {
        $value = $text->value ?? '';
        if (!str_ends_with($value, '{')) {
            return;
        }
        $pairedNext = match ($next?->type) {
            'strong' => !self::bracedPair($w, $next, ($flags & Spell::IN_STRONG) !== 0) && !self::bracedBy($after, '*'),
            'emphasis' => !self::bracedPair($w, $next, ($flags & Spell::IN_EMPHASIS) !== 0) && !self::bracedBy($after, '/'),
            default => false,
        };
        if ($pairedNext || $pairedEnd) {
            self::placeEscape($w, $text, strlen($value) - 1);
        }
    }

    private static function bracedPair(WriterState $w, Node $pair, bool $inside): bool
    {
        return PairForm::bracedAnyway($pair, $inside) || ($w->forms[spl_object_id($pair)] ?? 0) > 0;
    }

    /**
     * Whether the text `after` starts with `mark` or a word character, which braces the pair before it.
     */
    private static function bracedBy(?Node $after, string $mark): bool
    {
        if ($after?->type !== 'text' || ($after->value ?? '') === '') {
            return false;
        }

        return str_starts_with($after->value ?? '', $mark) || preg_match('/^[\p{L}\p{N}_]/u', $after->value ?? '') === 1;
    }

    private static function placeEscape(WriterState $w, Node $text, int $at): void
    {
        if (!in_array($at, $w->escapesOf($text), true)) {
            Round::addEscapes($w, [new Escape($text, $at)]);
        }
    }

    private static function caretLeads(WriterState $w, Node $node, ?Node $before): bool
    {
        $first = $node->children()[0] ?? null;
        if ($first?->type !== 'text' || !str_starts_with($first->value ?? '', '^')) {
            return false;
        }
        if ($node->type === 'link') {
            $form = $node->field('refForm');
            $key = $node->field('key');
            $keyIsContent = ($form === 'collapsed' || $form === 'shortcut') && is_string($key) && str_starts_with($key, '^');

            return !$keyIsContent && $w->recordedRaw($node) === null;
        }
        if ($node->type !== 'span') {
            return false;
        }
        $value = $before?->type === 'text' ? $before->value ?? '' : '';
        $bang = str_ends_with($value, '!') && !in_array(strlen($value) - 1, $w->escapesOf($before), true);

        return !$bang;
    }

    /**
     * The pairs a write braced for its neighbors. A write the memo of `Writer` answers runs no inline writer, so the
     * pairs of the first such write are kept with the same inputs.
     *
     * @param string $key
     * @param array<string, true> $glued
     *
     * @return array<string, true>
     */
    private static function rememberGlue(string $key, array $glued): array
    {
        if (isset(self::$glueMemo[$key])) {
            return $glued === [] ? self::$glueMemo[$key] : $glued;
        }
        if (count(self::$glueMemo) >= self::GLUE_MEMO_SIZE) {
            self::$glueMemo = [];
        }

        return self::$glueMemo[$key] = $glued;
    }

    /**
     * carve-js `gluedPart`: a pair the write braced for its neighbors takes the braced form, glued, so the next round
     * decides it again (carve#6420).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<string, true> $paths
     * @param string $prefix
     */
    public static function glue(WriterState $w, array $group, array $paths, string $prefix = '/children'): void
    {
        $pending = [];
        foreach ($group as $at => $node) {
            $pending[] = [$node, "{$prefix}/{$at}"];
        }
        while (($item = array_pop($pending)) !== null) {
            [$node, $path] = $item;
            if (isset($paths[$path])) {
                $id = spl_object_id($node);
                if (!isset($w->forms[$id])) {
                    $w->glued[$id] = true;
                }
                $w->forms[$id] = 1;
            }
            foreach ($node->slots as $name => $list) {
                foreach ($list as $at => $child) {
                    $single = $child->path === "{$node->path}/{$name}";
                    $pending[] = [$child, $single ? "{$path}/{$name}" : "{$path}/{$name}/{$at}"];
                }
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public function pairEscapes(WriterState $w, array $group): array
    {
        [$tree, $escapes] = self::document($w, $group);
        $texts = [];
        foreach ($group as $at => $node) {
            self::noteTexts($node, "/children/{$at}", $texts);
        }
        $found = [];
        foreach (Text::treePairOpeners($tree, $escapes) as $path => $offsets) {
            $text = $texts[$path] ?? null;
            if ($text === null) {
                continue;
            }
            foreach (array_keys($offsets) as $offset) {
                if (!isset($escapes[$path][$offset])) {
                    $found[] = new Escape($text, $offset);
                }
            }
        }

        return $found;
    }

    /**
     * C2-W-125 case five: a bracket without a partner in link text would take the link's own closer. The rounds write
     * these brackets bare until their first parse asks for a change inside a link (`linkFallback`), so a bare closer
     * reports the kinds of what it lets form (C2-W-088, carve#6656). True where the escapes of `w` changed.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<string, mixed> $tree
     */
    private static function escapeLoneLinkBrackets(WriterState $w, array $group, array $tree): bool
    {
        [$positions] = LinkFallback::positions($tree);
        if ($positions === []) {
            return false;
        }
        $w->sawLink = true;
        if (!$w->linkFallback) {
            return false;
        }
        $texts = [];
        foreach ($group as $at => $node) {
            self::noteTexts($node, "/children/{$at}", $texts);
        }
        $changed = false;
        foreach ($positions as $path => $offsets) {
            $text = $texts[$path] ?? null;
            if ($text === null) {
                continue;
            }
            $id = spl_object_id($text);
            $current = $w->escapes[$id] ?? [];
            $added = array_diff(array_keys($offsets), $current);
            if ($added === []) {
                continue;
            }
            $merged = [...$current, ...$added];
            sort($merged);
            $w->escapes[$id] = $merged;
            $w->escaped[$id] = $text;
            foreach ($added as $offset) {
                $w->linkPlaced[$id][$offset] = true;
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * The group as a document of its own, with the escapes of `w` by the paths its text nodes take there.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return array{array<string, mixed>, array<string, array<int, true>>}
     */
    private static function document(WriterState $w, array $group): array
    {
        $roots = [];
        $children = [];
        foreach ($group as $at => $node) {
            $roots[$node->path] = "/children/{$at}";
            $array = $node->toArray();
            self::markForms($w, $node, $array, [], true);
            $children[] = $array;
        }
        $escapes = [];
        foreach ($w->escapes as $id => $offsets) {
            $path = self::rebased($w->escaped[$id]->path ?? null, $roots);
            if ($path === null) {
                continue;
            }
            foreach ($offsets as $offset) {
                $escapes[$path][$offset] = true;
            }
        }

        return [['type' => 'document', 'children' => $children], $escapes];
    }

    /**
     * Gives each emphasis and strong node below `node` its form (carve-js `bracedPair`): braced for its content or
     * place, which `bracedAnyway` records, or where a round asked for it (`forms`), and bare otherwise.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param array<mixed> $array `node` as its array, changed in place.
     * @param array<string, true> $open The kinds of the emphasis and strong nodes around `node`.
     * @param bool $note
     */
    public static function markForms(WriterState $w, Node $node, array &$array, array $open, bool $note = false): void
    {
        if ($node->type === 'emphasis' || $node->type === 'strong') {
            $id = spl_object_id($node);
            $anyway = PairForm::bracedAnyway($node, isset($open[$node->type]));
            if ($anyway && $note) {
                $w->bracedAnyway[$id] = true;
                $w->shared->held[] = $node;
            }
            $array[Inline::BRACED_FORM] = $anyway || ($w->forms[$id] ?? 0) > 0;
            $open[$node->type] = true;
        }
        foreach ($node->slots as $name => $list) {
            if (!is_array($array[$name] ?? null)) {
                continue;
            }
            $slot = $array[$name];
            foreach ($list as $at => $child) {
                if ($child->path === "{$node->path}/{$name}") {
                    self::markForms($w, $child, $slot, $open, $note);
                } elseif (is_array($slot[$at] ?? null)) {
                    $item = $slot[$at];
                    self::markForms($w, $child, $item, $open, $note);
                    $slot[$at] = $item;
                }
            }
            $array[$name] = $slot;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param string $path
     * @param array<string, \MarkupCarve\Carve\Writer\Check\Node> $texts
     */
    private static function noteTexts(Node $node, string $path, array &$texts): void
    {
        if ($node->type === 'text') {
            $texts[$path] = $node;
        }
        foreach ($node->slots as $name => $list) {
            foreach ($list as $at => $child) {
                $own = $child->path === "{$node->path}/{$name}" ? "{$path}/{$name}" : "{$path}/{$name}/{$at}";
                self::noteTexts($child, $own, $texts);
            }
        }
    }

    /**
     * @param string|null $path
     * @param array<string, string> $roots
     */
    private static function rebased(?string $path, array $roots): ?string
    {
        if ($path === null) {
            return null;
        }
        foreach ($roots as $from => $to) {
            if ($path === $from || str_starts_with($path, $from . '/')) {
                return $to . substr($path, strlen($from));
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param string $path
     * @param array<string, array{int, int}> $ranges
     * @param list<int> $lineStarts
     */
    private static function notePlaces(WriterState $w, Node $node, string $path, array $ranges, array $lineStarts): void
    {
        if ($node->type === 'paragraph') {
            // The marked write ranges the inlines, so a paragraph starts where its first inline starts.
            $start = null;
            foreach ($ranges as $own => [$from]) {
                if (str_starts_with($own, $path . '/') && ($start === null || $from < $start)) {
                    $start = $from;
                }
            }
            if ($start === null) {
                return;
            }
            $line = 0;
            while (isset($lineStarts[$line + 1]) && $lineStarts[$line + 1] <= $start) {
                $line++;
            }
            $column = $start - $lineStarts[$line];
            if ($column < WriterState::PLACE_COLUMNS) {
                $w->starts[] = [$node, $line * WriterState::PLACE_COLUMNS + $column];
            }

            return;
        }
        foreach ($node->slots as $name => $list) {
            foreach ($list as $at => $child) {
                $own = $child->path === "{$node->path}/{$name}" ? "{$path}/{$name}" : "{$path}/{$name}/{$at}";
                self::notePlaces($w, $child, $own, $ranges, $lineStarts);
            }
        }
    }
}
