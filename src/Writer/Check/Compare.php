<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use Closure;
use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\ReadbackRound;

/**
 * The escaping test of the writer (C2-W-088 to C2-W-091), a port of carve-js write-check.ts in its order: the written
 * blocks are parsed again and compared with the tree they were written from. Where the parse reads a construct that
 * the tree does not hold, the opener of that construct takes a backslash; where a construct of the tree did not form,
 * it takes its other spelling (C2-W-005). What neither repairs is a loss. Offsets are bytes of a text's value.
 */
final class Compare
{
    /**
     * C2-A-162: positions and the results of resolution are not compared.
     *
     * @var list<string>
     */
    private const NODE_SLOTS = ['children', 'caption'];

    /**
     * @var array<string, true>
     */
    private const IGNORED_DATA = ['carve.source' => true, 'carve.style' => true, 'carve.annotation' => true];

    /**
     * The characters that read on into markup that stands directly behind them.
     *
     * @var string
     */
    private const JOINING = '\\!^$`';

    /**
     * The closing mark of each node that has one, for the escape of that mark in its content (C2-W-093).
     *
     * @var array<string, string>
     */
    public const CLOSING_MARKS = [
        'emphasis' => '/',
        'strong' => '*',
        'underline' => '_',
        'strike' => '~',
        'highlight' => '=',
        'superscript' => '^',
        'subscript' => ',',
        'insert' => '+',
        'delete' => '-',
        'span' => ']',
        'link' => ']',
        'inline_footnote' => ']',
        'inline_extension' => ']',
    ];

    /**
     * The nodes whose content a `]` closes.
     *
     * @var array<string, true>
     */
    public const BRACKETED = ['link' => true, 'span' => true, 'inline_footnote' => true, 'inline_extension' => true];

    /**
     * @var array<string, true>
     */
    private const VERBATIM = ['code' => true, 'literal_inline' => true, 'math' => true, 'raw_inline' => true];

    /**
     * @var array<string, true>
     */
    private const BRACKET_CONSTRUCTS = [
        'footnote_ref' => true,
        'inline_footnote' => true,
        'link' => true,
        'span' => true,
        'image' => true,
        'inline_extension' => true,
    ];

    /**
     * The constructs a backtick run opens, alone or behind `!` or `$` (C2-I-014, C2-I-018).
     *
     * @var array<string, true>
     */
    private const SPAN_TYPES = ['code' => true, 'literal_inline' => true, 'math' => true];

    /**
     * The marks of braced openers (C2-I-055), carve-js `BRACED_MARKS`.
     *
     * @var string
     */
    private const BRACED_MARKS = '*+,-/=^_~';

    /**
     * @var array<string, true>
     */
    private const INLINE_PARENTS = [
        'paragraph' => true,
        'heading' => true,
        'definition_term' => true,
        'cell_paragraph' => true,
        'line' => true,
        'citation_definition' => true,
        'emphasis' => true,
        'strong' => true,
        'underline' => true,
        'strike' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
        'insert' => true,
        'delete' => true,
        'span' => true,
        'link' => true,
        'inline_footnote' => true,
        'inline_extension' => true,
        'small_caps' => true,
    ];

    /**
     * Whether the children of `$node` are inlines (carve-js `isInlineParent`).
     */
    public static function isInlineParent(Node $node): bool
    {
        return isset(self::INLINE_PARENTS[$node->type]);
    }

    /**
     * @param list<mixed> $a
     * @param mixed $b
     */
    private static function sameList(array $a, mixed $b): bool
    {
        if (!is_array($b) || !array_is_list($b) || count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $at => $item) {
            if (!self::sameValue($item, $b[$at])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private static function sameFields(array $left, array $right): bool
    {
        foreach ($left as $name => $value) {
            if (!self::skipped($left, (string)$name, false) && $value !== null && !self::sameValue($value, $right[$name] ?? null)) {
                return false;
            }
        }
        foreach ($right as $name => $value) {
            if (!self::skipped($right, (string)$name, false) && $value !== null && !isset($left[$name])) {
                return false;
            }
        }

        return true;
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if (is_array($a) && array_is_list($a)) {
            return self::sameList($a, $b);
        }

        return is_array($a) && is_array($b) && !array_is_list($b) ? self::sameFields($a, $b) : $a === $b;
    }

    /**
     * C2-A-162: only typed nodes have geometry and the generated resolution results.
     *
     * @param array<mixed> $node
     * @param bool $spanMarks
     * @param string $name
     */
    private static function skipped(array $node, string $name, bool $spanMarks): bool
    {
        $type = $node['type'] ?? null;
        if (!is_string($type) || !isset(Tables::AST_RESULTS[$type])) {
            return false;
        }
        if ($name === 'pos' || $name === 'segments' || $name === 'srcByteLength') {
            return true;
        }
        $when = Tables::AST_RESULTS[$type][$name] ?? null;
        if ($when === null) {
            return false;
        }
        if ($when === 'reference use') {
            return isset($node['key']);
        }
        if ($when === 'derived from authored span mark') {
            return $spanMarks;
        }

        return true;
    }

    private static function isCompared(Check $c, Node $node, string $name): bool
    {
        // C2-A-162: `skipped` reads the position from the carve-js result table, where the PHP tree packs it in `pos`.
        if ($name === 'pos' || in_array($name, self::NODE_SLOTS, true)) {
            return false;
        }
        $data = $node->field('data');
        if ($name === 'data' && is_array($data) && array_diff_key($data, self::IGNORED_DATA) === []) {
            return false;
        }
        if ($name === 'title') {
            return !isset($node->slots['title']);
        }

        return !self::skipped($node->fields + ['type' => $node->type], $name, $c->spanMarks);
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-W-136, C2-W-050: the `attrs` of `e` as the parse holds them: without the keys the writer reported and did not
     * write, and with each value in the spelling the writer gave it.
     */
    private static function writtenAttrs(Check $c, Node $e): mixed
    {
        $keys = $c->w->lostKeys[spl_object_id($e)] ?? null;
        $attrs = $e->field('attrs');
        if (!is_array($attrs)) {
            return $attrs;
        }
        $pairs = is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [];
        $respelled = false;
        foreach ($pairs as $pair) {
            $respelled = $respelled || (is_array($pair) && is_string($pair[1] ?? null) && str_contains($pair[1], "\n"));
        }
        if ($keys === null && !$respelled) {
            return $attrs;
        }
        $keyValues = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                continue;
            }
            if ($keys === null || !in_array($pair[0], $keys, true)) {
                $keyValues[] = [$pair[0], self::spelledValue($pair[1])];
            }
        }
        $rest = $attrs;
        unset($rest['keyValues']);
        if ($keyValues !== []) {
            $rest['keyValues'] = $keyValues;
        }
        $classes = is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [];

        return !isset($rest['id']) && $classes === [] && $keyValues === [] ? null : $rest;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * carve-js `spelledValue` (write-attributes.ts): a line feed of a value is written as a space.
     */
    private static function spelledValue(string $value): string
    {
        return str_replace("\n", ' ', $value);
    }

    /**
     * The name of the first field in which `e` and `a` differ, or null.
     */
    public static function differingField(Check $c, Node $e, Node $a): ?string
    {
        foreach ($e->fields as $name => $field) {
            $name = (string)$name;
            $value = $name === 'attrs' ? self::writtenAttrs($c, $e) : $field;
            if ($value !== null && self::isCompared($c, $e, $name) && !self::sameValue($value, $a->fields[$name] ?? null)) {
                return $name;
            }
        }
        foreach ($a->fields as $name => $value) {
            $name = (string)$name;
            if ($value !== null && !isset($e->fields[$name]) && self::isCompared($c, $a, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Asks for the other spelling of `node`.
     */
    public static function changeForm(Check $c, Node $node): void
    {
        $c->forms[] = $node;
        $c->respelled[spl_object_id($node)] = $node;
    }

    public static function mark(Check $c, Node $node, int $at, bool $escaped): void
    {
        $c->marks[spl_object_id($node)][$at] = $escaped;
    }

    public static function isEscaped(Check $c, Node $node, int $at): bool
    {
        $asked = $c->marks[spl_object_id($node)][$at] ?? null;
        if ($asked !== null) {
            return $asked;
        }

        return in_array($at, $c->w->escapesOf($node), true);
    }

    /**
     * Asks for a backslash before the character `at` of the text node. False where that character takes none.
     */
    public static function escapeAt(Check $c, Node $node, int $at): bool
    {
        $value = $node->value ?? '';
        if ($at < 0 || $at >= strlen($value) || !self::isAsciiPunctuation($value[$at]) || self::isEscaped($c, $node, $at)) {
            return false;
        }
        $c->escapes[] = new Escape($node, $at);
        self::mark($c, $node, $at, true);

        return true;
    }

    public static function isAsciiPunctuation(string $char): bool
    {
        return $char !== '' && strlen($char) === 1 && ctype_punct($char);
    }

    private static function isAsciiAlphanumeric(string $char): bool
    {
        return $char !== '' && strlen($char) === 1 && ctype_alnum($char);
    }

    /**
     * The offsets at which a line that reads as a block opener can be made text, best first (C2-W-092).
     * C2-W-099: of a hyphen run the second hyphen. An ordered marker is escaped at its delimiter,
     * since a backslash before a digit or a letter is no escape (C2-I-012).
     *
     * @return list<int>
     */
    public static function openerOffsets(string $value): array
    {
        $offsets = [];
        if (str_starts_with($value, '--')) {
            $offsets[] = 1;
        }
        $offsets[] = 0;
        $end = 0;
        $valueLength = strlen($value);
        while ($end < $valueLength && self::isAsciiAlphanumeric($value[$end])) {
            $end++;
        }
        if ($end > 0 && in_array($value[$end] ?? '', ['.', ')'], true)) {
            $offsets[] = $end;
        }

        return $offsets;
    }

    /**
     * The text nodes from `nodes[from]` up to the first other node: the opener of a line may span them (C2-W-092).
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $from
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    private static function leadingTexts(array $nodes, int $from): array
    {
        $texts = [];
        for ($at = $from; ($nodes[$at] ?? null)?->type === 'text'; $at++) {
            $texts[] = $nodes[$at];
        }

        return $texts;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $texts
     */
    private static function joinedValue(array $texts): string
    {
        return implode('', array_map(static fn (Node $text): string => $text->value ?? '', $texts));
    }

    /**
     * C2-B-130, C2-W-090: the spaces that lead a line of a line block are content, so its opener is escaped at its
     * first mark behind them.
     *
     * @return list<int>
     */
    private static function lineOpenerOffsets(string $value): array
    {
        $lead = strspn($value, ' ');

        return $lead > 0 && $lead < strlen($value) ? [$lead] : [];
    }

    /**
     * Escapes the opener of the text that `texts` hold joined, at the character it falls on in whichever node.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $texts
     * @param bool $inLine
     */
    private static function escapeLeadingOpener(Check $c, array $texts, bool $inLine = false): bool
    {
        if ($texts === []) {
            return false;
        }
        $value = self::joinedValue($texts);
        $offsets = $inLine ? [...self::openerOffsets($value), ...self::lineOpenerOffsets($value)] : self::openerOffsets($value);
        foreach ($offsets as $offset) {
            $k = 0;
            $last = count($texts) - 1;
            while ($k < $last) {
                $length = strlen($texts[$k]->value ?? '');
                if ($offset < $length) {
                    break;
                }
                $offset -= $length;
                $k++;
            }
            if (!self::escapeAt($c, $texts[$k], $offset)) {
                continue;
            }
            // C2-W-088: the line kind is read from the first text of the line; a run split over nodes gives no kind.
            if ($k === 0) {
                $c->lineEscapes[spl_object_id($texts[$k])][$offset] = true;
            } else {
                $c->ownEscapes[spl_object_id($texts[$k])][$offset] = true;
            }

            return true;
        }

        return false;
    }

    /**
     * The line that starts at `nodes[from]` opened a block: escapes its opener (C2-W-090, C2-W-092).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param int $from
     */
    public static function escapeLineStart(Check $c, array $nodes, int $from, Node $owner): void
    {
        $c->misaligned = true;
        if (self::escapeAttributeLinesAbove($c, $nodes, $from, $owner)) {
            return;
        }
        $first = $nodes[$from] ?? null;
        if (self::escapeLeadingOpener($c, self::leadingTexts($nodes, $from), $owner->type === 'line')) {
            return;
        }
        $nodesCount = count($nodes);
        for ($at = $from + 1; $at < $nodesCount && !$nodes[$at]->isBreak(); $at++) {
            if ($nodes[$at]->type === 'text' && self::escapeAt($c, $nodes[$at], 0)) {
                return;
            }
        }
        // The markup of the first node of the line reads as a block opener: its other spelling may not (C2-W-005).
        if ($first !== null && self::hasOtherForm($c, $first)) {
            self::changeForm($c, $first);
        } else {
            $c->losses[] = [$first ?? $owner, null];
        }
    }

    /**
     * C2-I-028: where every line of the block above `nodes[from]` is a whole attribute block, they read as attribute
     * lines and the line at `from` starts a block of its own; the first brace takes the backslash (C2-W-125 case one).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param int $from
     */
    private static function escapeAttributeLinesAbove(Check $c, array $nodes, int $from, Node $owner): bool
    {
        $first = $nodes[0] ?? null;
        if (
            $first?->type !== 'text' || $from === 0 || !($nodes[$from - 1] ?? null)?->isBreak()
            || ($owner->children()[0] ?? null) !== $first
        ) {
            return false;
        }
        $line = '';
        for ($at = 0; $at < $from; $at++) {
            $node = $nodes[$at];
            if ($node->isBreak()) {
                $text = rtrim($line, " \t");
                if (($text[0] ?? '') !== '{' || !self::isAttributeBlock($text)) {
                    return false;
                }
                $line = '';
            } elseif ($node->type === 'text') {
                $line .= $node->value ?? '';
            } else {
                return false;
            }
        }

        return self::escapeAt($c, $first, 0);
    }

    /**
     * Whether `$text` is one whole attribute block (carve-js `readAttributeBlock(text, 0, length) === length`).
     */
    private static function isAttributeBlock(string $text): bool
    {
        return AttributeParser::read($text, 0, strlen($text), new Attrs()) === strlen($text);
    }

    public static function hasOtherForm(Check $c, Node $node): bool
    {
        if ($c->w->form($node) > 0 || isset($c->respelled[spl_object_id($node)])) {
            return false;
        }
        // C2-W-069: a pair braced for its content or place has no bare form; asking for one would only restart the rounds.
        if ($node->type === 'emphasis' || $node->type === 'strong') {
            return !$c->w->isBracedAnyway($node);
        }
        if ($node->type === 'comment') {
            return true;
        }

        return $c->w->recordedRaw($node) !== null;
    }

    /**
     * The last text node below `node`, or `node` itself where it is one.
     */
    public static function lastText(?Node $node): ?Node
    {
        for ($at = $node;;) {
            if ($at === null) {
                return null;
            }
            if ($at->type === 'text') {
                return $at;
            }
            $children = $at->children();
            $at = $children === [] ? null : $children[count($children) - 1];
        }
    }

    /**
     * Whether `value` holds, from `at` on, spaces or tabs and then a CR.
     */
    private static function endsLineWithCr(string $value, int $at): bool
    {
        $k = $at + strspn($value, " \t", $at);

        return ($value[$k] ?? '') === "\r";
    }

    /**
     * Asks for a backslash in `text` where its end reads on into the markup behind it: before a last character that
     * joins that markup, or else before its last opening brace, which may open a brace form that takes the markup
     * (C2-I-043).
     */
    public static function escapeEnd(Check $c, ?Node $text, bool $braces, ?string $behind = null): bool
    {
        if ($text === null) {
            return false;
        }
        $value = $text->value ?? '';
        $last = $value === '' ? '' : $value[strlen($value) - 1];
        if (
            $last !== '' && str_contains(self::JOINING, $last) && ($behind === null || self::joins($last, $behind))
            && self::escapeAt($c, $text, strlen($value) - 1)
        ) {
            return true;
        }
        $brace = strrpos($value, '{');

        return $braces && $brace !== false && self::escapeAt($c, $text, $brace);
    }

    /**
     * Whether the character `last` reads on into the markup `behind`.
     */
    private static function joins(string $last, string $behind): bool
    {
        if ($last === '\\' || $last === '`') {
            return $behind !== '';
        }

        return $last === '$' ? str_starts_with($behind, '`') : str_starts_with($behind, '[');
    }

    /**
     * Asks for a backslash before the first bare closing mark of `e` in its content, or before the first bracket in it.
     * C2-W-125, case five: of brackets, each bare `]` that closes no bare `[` of the content ends `e` early in turn.
     */
    public static function escapeInContent(Check $c, Node $e): bool
    {
        $mark = self::CLOSING_MARKS[$e->type] ?? null;
        if ($mark === null || !$e->hasChildren()) {
            return false;
        }
        if (
            $mark === ']'
            && (self::escapeLoneClosers($c, $e) || (!self::readsBracketConstruct($c, $e) && self::escapeUnclosedBrackets($c, $e)))
        ) {
            return true;
        }

        return self::escapeCloser($c, $e->children(), 0, 0, $mark)
            || ($mark === ']' && self::escapeMark($c, $e->children(), 0, 0, '['));
    }

    private static function escapeLoneClosers(Check $c, Node $e): bool
    {
        $asked = false;
        $open = [];
        $inner = null;
        $bare = static fn (Node $node, int $at): bool => !self::isEscaped($c, $node, $at);
        $closers = self::contentClosers($e->children(), $bare, $inner, $open);
        foreach ($closers as [$text, $at, $partner]) {
            if ($partner === null) {
                $asked = self::escapeAt($c, $text, $at) || $asked;
            }
        }

        return $asked;
    }

    /**
     * The bare `]` in `nodes`, the content of a bracketed node, each with the bare `[` of that content it closes, or
     * null. A bracketed node inside has its own content: it is skipped, or pushed to `inner` where that is given.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $bare
     * @param list<\MarkupCarve\Carve\Writer\Check\Node>|null $inner
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> $open
     *
     * @return list<array{\MarkupCarve\Carve\Writer\Check\Node, int, array{\MarkupCarve\Carve\Writer\Check\Node, int}|null}>
     */
    public static function contentClosers(array $nodes, Closure $bare, ?array &$inner, array &$open): array
    {
        $closers = [];
        $pending = array_reverse($nodes);
        while (($node = array_pop($pending)) !== null) {
            if (isset(self::BRACKETED[$node->type])) {
                if ($inner !== null) {
                    $inner[] = $node;
                }
            } elseif ($node->type === 'text') {
                self::pairBrackets($node, $bare, $open, $closers);
            } else {
                $children = $node->children();
                for ($at = count($children) - 1; $at >= 0; $at--) {
                    $pending[] = $children[$at];
                }
            }
        }

        return $closers;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $bare
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> $open
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, int, array{\MarkupCarve\Carve\Writer\Check\Node, int}|null}> $closers
     */
    private static function pairBrackets(Node $node, Closure $bare, array &$open, array &$closers): void
    {
        $value = $node->value ?? '';
        for ($at = 0, $length = strlen($value); $at < $length; $at++) {
            $ch = $value[$at];
            if (($ch !== '[' && $ch !== ']') || !$bare($node, $at)) {
                continue;
            }
            if ($ch === '[') {
                $open[] = [$node, $at];
            } else {
                $closers[] = [$node, $at, array_pop($open)];
            }
        }
    }

    /**
     * Whether the text before `es[i]` ends in a character that reads on into the markup of `es[i]` (C2-W-125).
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function joinsNext(array $es, int $i): bool
    {
        $value = $i > 0 ? (self::lastText($es[$i - 1] ?? null)->value ?? '') : '';

        return $value !== '' && str_contains(self::JOINING, $value[strlen($value) - 1]);
    }

    /**
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    public static function textsBelow(Node $node): array
    {
        $texts = [];
        foreach ($node->children() as $child) {
            array_push($texts, ...($child->type === 'text' ? [$child] : self::textsBelow($child)));
        }

        return $texts;
    }

    /**
     * C2-W-026, C2-W-092: a line inside the content of `e` that has the shape of a block opener may have opened a
     * block and so ended `e`. The first such line takes the escape of its opener.
     */
    public static function escapeContentLine(Check $c, Node $e): bool
    {
        if (!$e->hasChildren()) {
            return false;
        }
        $nodes = $e->children();
        foreach ($nodes as $at => $node) {
            if ($node->hasChildren() && self::escapeContentLine($c, $node)) {
                return true;
            }
            $next = $nodes[$at + 1] ?? null;
            if (
                !$node->isBreak() || $next?->type !== 'text' || !self::hasOpenerShape($nodes, $at + 1)
                || !self::opensBlockAlone($c, $nodes, $at + 1)
            ) {
                continue;
            }
            if (self::escapeLeadingOpener($c, self::leadingTexts($nodes, $at + 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the line that starts at `nodes[from]`, written alone, opens a block: its parse is no single paragraph.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $from
     */
    private static function opensBlockAlone(Check $c, array $nodes, int $from): bool
    {
        $end = $from;
        $nodesCount = count($nodes);
        while ($end < $nodesCount && !$nodes[$end]->isBreak()) {
            $end++;
        }
        $line = $c->w->spell->inlines($c->w, array_slice($nodes, $from, $end - $from), $c->flags);
        $known = $c->w->shared->lineOpens[$line] ?? null;
        if ($known !== null) {
            return $known;
        }
        $blocks = self::parseChunk($c->w, $line)->children();
        $opens = count($blocks) !== 1 || $blocks[0]->type !== 'paragraph';
        $c->w->shared->lineOpens[$line] = $opens;

        return $opens;
    }

    /**
     * The tree of `$source`, with positions, as mirror nodes (carve-js `parseChunk`). Each call is counted.
     */
    public static function parseChunk(WriterState $w, string $source): Node
    {
        $w->shared->parses++;
        $w->shared->chars += strlen($source);

        // A group is read with the link keys and abbreviations of its document in place of its own (carve-js `parsedTree`).
        $options = $w->options + ['scope' => ['linkKeys' => $w->linkKeys, 'abbreviations' => $w->abbreviations]];
        $parsed = Node::mirror(ReadbackRound::read($source, $options));
        $parsed->place($source);

        return $parsed;
    }

    /**
     * How far behind the start of the construct `a` its opener takes the backslash (C2-W-090, C2-W-099).
     */
    private static function openerShift(Node $a): int
    {
        if ($a->type === 'image' || $a->type === 'literal_inline' || $a->type === 'inline_footnote') {
            return 1;
        }
        if ($a->type === 'math') {
            return $a->field('display') === true ? 2 : 1;
        }
        if ($a->type !== 'smart_punctuation') {
            return 0;
        }
        $source = $a->string('source') ?? '';

        // The second hyphen, also of the braced en dash `{--}` (C2-I-043).
        return str_starts_with($source, '{-') ? 2 : (str_starts_with($source, '-') ? 1 : 0);
    }

    /**
     * The text of `e` holds, at `at`, what the parse read as the construct `a`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param int $at
     * @param int $i
     */
    private static function escapeOpener(Check $c, array $es, int $i, int $at, Node $a): void
    {
        $e = $es[$i];
        $value = $e->value ?? '';
        // C2-I-023: a line comment takes the spaces before it.
        $start = $at + strspn($value, " \t", min($at, strlen($value)));
        if ($a->type === 'inline_footnote' && self::escapeEarlyNoteCloser($c, $es, $i, $start)) {
            return;
        }
        // C2-W-099: the shifted character is one of the source run, which may go on in the text nodes behind `e`.
        $holder = $e;
        $shifted = $start + self::openerShift($a);
        for ($k = $i + 1; ($es[$k] ?? null)?->type === 'text'; $k++) {
            $length = strlen($holder->value ?? '');
            if ($shifted < $length) {
                break;
            }
            $shifted -= $length;
            $holder = $es[$k];
        }
        if (self::escapeAt($c, $holder, $shifted)) {
            $opener = ($holder->value ?? '')[$shifted] ?? '';
            if (($opener === '[' && self::isChainOpener($a)) || ($opener === '`' && isset(self::SPAN_TYPES[$a->type]))) {
                $c->chains[] = [$holder, $shifted, $a];
            }
        } elseif (!self::escapeAt($c, $e, $start)) {
            $c->losses[] = [$e, 'value'];
        }
    }

    /**
     * C2-W-125, case eight: a text `^[` whose span keeps a caret pair of the tree ordinary (C2-I-089) stays bare, and
     * the bare `]` that closed it early takes the backslash. Each round reaches the next such `]` in turn.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $start
     * @param int $i
     */
    private static function escapeEarlyNoteCloser(Check $c, array $es, int $i, int $start): bool
    {
        $value = $es[$i]->value ?? '';
        // The `[` of the opener can start the next text node of a supplied tree.
        $split = $start + 1 === strlen($value);
        $bracket = $split
            ? (($es[$i + 1] ?? null)?->type === 'text' ? (($es[$i + 1]->value ?? '')[0] ?? '') : '')
            : ($value[$start + 1] ?? '');
        if (($value[$start] ?? '') !== '^' || $bracket !== '[' || !self::anyCaretPair(array_slice($es, $i + 1))) {
            return false;
        }
        if (isset($c->notesDone[spl_object_id($es[$i])][$start])) {
            return true;
        }
        $closer = $split ? self::earlyNoteCloser($c, $es, $i + 1, 1) : self::earlyNoteCloser($c, $es, $i, $start + 2);
        if ($closer === null || !self::escapeCloserOf($c, $closer)) {
            return false;
        }
        $c->earlyCloser = true;
        self::laterNoteClosers($c, $es, $i, $start + 2);

        return true;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     */
    private static function anyCaretPair(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (self::holdsCaretPair($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param array{\MarkupCarve\Carve\Writer\Check\Node, int} $closer
     */
    private static function escapeCloserOf(Check $c, array $closer): bool
    {
        if (!self::escapeAt($c, $closer[0], $closer[1])) {
            return false;
        }
        Check::add($c->closers, $c->last());

        return true;
    }

    /**
     * C2-W-088: each later text `^[` before a caret pair takes its early closer in the same round.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $from
     * @param int $i
     */
    private static function laterNoteClosers(Check $c, array $es, int $i, int $from): void
    {
        $esCount = count($es);
        for ($k = $i; $k < $esCount; $k++) {
            $text = $es[$k];
            if ($text->type !== 'text' || !self::anyCaretPair(array_slice($es, $k + 1))) {
                continue;
            }
            $value = $text->value ?? '';
            $first = $k === $i ? min($from, strlen($value)) : 0;
            for ($at = strpos($value, '^[', $first); $at !== false; $at = strpos($value, '^[', $at + 2)) {
                if (self::isEscaped($c, $text, $at) || self::isEscaped($c, $text, $at + 1)) {
                    continue;
                }
                $closer = self::earlyNoteCloser($c, $es, $k, $at + 2);
                if ($closer === null || !self::escapeCloserOf($c, $closer)) {
                    continue;
                }
                $c->notesDone[spl_object_id($text)][$at] = true;
                if ($at + 2 > strlen($value)) {
                    break;
                }
            }
        }
    }

    /**
     * The bare `]` of the text from `es[i]` at `from` on that no bare `[` there pairs with, or null.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $from
     * @param int $i
     *
     * @return array{\MarkupCarve\Carve\Writer\Check\Node, int}|null
     */
    private static function earlyNoteCloser(Check $c, array $es, int $i, int $from): ?array
    {
        $depth = 0;
        // A node of the tree between, as a strong, a code span or a link, holds no bracket of the note.
        $esCount = count($es);
        for ($k = $i; $k < $esCount; $k++) {
            $text = $es[$k];
            $at = $text->type === 'text' ? self::unpairedCloser($c, $text, $k === $i ? $from : 0, $depth) : -1;
            if ($at >= 0) {
                return [$text, $at];
            }
        }

        return null;
    }

    private static function unpairedCloser(Check $c, Node $text, int $from, int &$depth): int
    {
        $value = $text->value ?? '';
        for ($at = $from, $length = strlen($value); $at < $length; $at++) {
            $char = $value[$at];
            if (($char !== '[' && $char !== ']') || self::isEscaped($c, $text, $at)) {
                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($depth-- === 0) {
                return $at;
            }
        }

        return -1;
    }

    /**
     * carve-js `leadingCaret` (write-inline.ts): link or span text that starts with a caret.
     */
    public static function leadingCaret(Node $node): bool
    {
        if ($node->type !== 'link' && $node->type !== 'span') {
            return false;
        }
        $first = $node->children()[0] ?? null;

        return $first?->type === 'text' && str_starts_with($first->value ?? '', '^');
    }

    /**
     * Whether `node` or a node below it is link or span text starting with a caret, the bracket of which forms `[^`.
     * One inside an inline footnote opens nothing there (C2-I-089), so it does not count.
     */
    public static function holdsCaretPair(Node $node): bool
    {
        return self::leadingCaret($node) || ($node->type !== 'inline_footnote' && self::anyCaretPair($node->children()));
    }

    /**
     * Whether the construct `a` forms from a bracket whatever lies between it and its closer (C2-I-076, C2-I-087).
     */
    private static function isChainOpener(Node $a): bool
    {
        if ($a->type === 'footnote_ref') {
            return true;
        }
        if (!in_array($a->type, ['link', 'image', 'span', 'inline_footnote'], true)) {
            return false;
        }

        return $a->field('key') === null;
    }

    /**
     * Whether the content of `node`, its text or the alternative text of an image, holds `]`.
     */
    private static function holdsCloser(Node $node): bool
    {
        if ($node->type === 'image' && str_contains($node->string('alt') ?? '', ']')) {
            return true;
        }
        foreach ($node->children() as $child) {
            if ($child->type === 'text' ? str_contains($child->value ?? '', ']') : self::holdsCloser($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bracket at `at` of the text `node` opened the construct `a`, which `isChainOpener` names. The brackets of
     * its run that form the same construct one per parse are placed in one round (C2-W-088, C2-I-118).
     */
    private static function escapeBrackets(Check $c, Node $node, int $at, Node $a): void
    {
        $value = $node->value ?? '';
        [$start, $end] = self::bracketRun($value, $at);
        // The closer stands in this text: one in a later node, such as a code span, can be taken from it by another construct.
        if (strpos($value, ']', min($end, strlen($value))) === false) {
            return;
        }
        if ($a->type === 'footnote_ref') {
            self::escapeLabels($c, $node, $at, $end);

            return;
        }
        if (self::holdsCloser($a)) {
            return;
        }
        $sigil = self::sigilOf($value, $at);
        $opens = static fn (int $k): bool => self::sigilOf($value, $k) === $sigil && ($sigil === '^' || ($value[$k + 1] ?? '') !== '^');
        for ($k = $at - 1; $k >= $start; $k--) {
            if ($value[$k] !== '[') {
                continue;
            }
            if (!$opens($k)) {
                break;
            }
            self::escapeAt($c, $node, $k);
        }
        for ($k = $at + 1; $k < $end; $k++) {
            if ($value[$k] !== '[') {
                continue;
            }
            if (!$opens($k)) {
                return;
            }
            self::escapeAt($c, $node, $k);
        }
    }

    /**
     * The image or footnote sigil glued before the bracket at `at`, or none.
     */
    private static function sigilOf(string $value, int $at): string
    {
        $before = $at > 0 ? $value[$at - 1] : '';

        return $before === '!' || $before === '^' ? $before : '';
    }

    /**
     * The start and the end of the run of brackets around `at`, each with an image or footnote sigil at most.
     *
     * @return array{int, int}
     */
    private static function bracketRun(string $value, int $at): array
    {
        $inRun = static fn (int $k): bool => ($value[$k] ?? '') === '['
            || ((($value[$k] ?? '') === '!' || ($value[$k] ?? '') === '^') && ($value[$k + 1] ?? '') === '[');
        $start = $at;
        while ($start > 0 && $inRun($start - 1)) {
            $start--;
        }
        $end = $at + 1;
        $valueLength = strlen($value);
        while ($end < $valueLength && $inRun($end)) {
            $end++;
        }

        return [$start, $end];
    }

    /**
     * The `[^` behind the footnote reference at `at`, up to the run end `end` (C2-I-079).
     */
    private static function escapeLabels(Check $c, Node $node, int $at, int $end): void
    {
        $value = $node->value ?? '';
        for ($k = $at + 1; $k < $end; $k++) {
            if ($value[$k] !== '[') {
                continue;
            }
            if (($value[$k + 1] ?? '') !== '^' || $k + 2 >= $end) {
                return;
            }
            self::escapeAt($c, $node, $k);
        }
    }

    /**
     * C2-W-088: source offsets and the last bare backtick, read once for all chains in a comparison. Offsets are
     * bytes in both engines' terms here, so the map is the identity on the wanted offsets.
     *
     * @param string $source
     * @param list<int> $wanted
     *
     * @return array{array<int, int>, int}
     */
    private static function backtickIndex(string $source, array $wanted): array
    {
        $offsets = [];
        foreach ($wanted as $byte) {
            $offsets[$byte] = min($byte, strlen($source));
        }
        $lastBare = -1;
        $slashes = 0;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            $char = $source[$at];
            if ($char === '`' && $slashes % 2 === 0) {
                $lastBare = $at;
            }
            $slashes = $char === '\\' ? $slashes + 1 : 0;
        }

        return [$offsets, $lastBare];
    }

    /**
     * C2-W-088: each equal-length run before the last one becomes the next opener when the one before it is escaped.
     */
    private static function escapeFollowingRuns(Check $c, Node $node, int $from, int $width): void
    {
        $value = $node->value ?? '';
        $starts = [];
        for ($start = strpos($value, '`', min($from, strlen($value))); $start !== false;) {
            $end = $start + 1;
            while (($value[$end] ?? '') === '`') {
                $end++;
            }
            if ($end - $start !== $width) {
                return;
            }
            $starts[] = $start;
            $start = $end >= strlen($value) ? false : strpos($value, '`', $end);
        }
        $startsCount = count($starts);
        for ($at = 0; $at + 1 < $startsCount; $at++) {
            self::escapeAt($c, $node, $starts[$at]);
        }
    }

    /**
     * The backtick at `at` of the text `node` opened the code span `a`, which has no closing run: each backtick of the
     * run is escaped in turn (C2-I-017), placed in one round.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param array{array<int, int>, int}|null $index
     */
    private static function escapeBackticks(Check $c, Node $node, int $at, Node $a, ?array $index): void
    {
        $source = $c->source;
        $value = $node->value ?? '';
        $run = $at;
        while (($value[$run] ?? '') === '`') {
            $run++;
        }
        if ($source === null) {
            return;
        }
        if ($a->start === null || $index === null) {
            self::escapeFollowingRuns($c, $node, $run, $run - $at);

            return;
        }
        $from = $index[0][$a->start] ?? strlen($source);
        $start = strpos($source, '`', min($from, strlen($source)));
        if ($start === false) {
            return;
        }
        $end = $start + $run - $at;
        while ($start < $end && ($source[$start] ?? '') === '`') {
            $start++;
        }
        if ($start !== $end || ($source[$end] ?? '') === '`' || $index[1] >= $end) {
            return;
        }
        for ($k = $at + 1; $k < $run; $k++) {
            self::escapeAt($c, $node, $k);
        }
    }

    /**
     * Extends the escapes of `chains` that this round places by the escapes the procedure would place behind each.
     */
    public static function extendChains(Check $c): void
    {
        if ($c->chains === []) {
            return;
        }
        $placed = [];
        foreach ($c->escapes as $escape) {
            $placed[spl_object_id($escape->node)][$escape->at] = true;
        }
        $offsets = [];
        foreach ($c->chains as [$node, $at, $a]) {
            if (isset($placed[spl_object_id($node)][$at]) && ($node->value ?? '')[$at] === '`' && $a->start !== null) {
                $offsets[] = $a->start;
            }
        }
        $index = $c->source === null || $offsets === [] ? null : self::backtickIndex($c->source, $offsets);
        foreach ($c->chains as [$node, $at, $a]) {
            if (!isset($placed[spl_object_id($node)][$at])) {
                continue;
            }
            if (($node->value ?? '')[$at] === '`') {
                self::escapeBackticks($c, $node, $at, $a, $index);
            } else {
                self::escapeBrackets($c, $node, $at, $a);
            }
        }
    }

    public static function isPaired(Node $node): bool
    {
        return $node->type === 'emphasis' || $node->type === 'strong';
    }

    /**
     * `escapeMark` for the closing mark of a node that ended early; the escape is kept as one of `c.closers`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param string $mark
     * @param int $from
     * @param int $start
     */
    public static function escapeCloser(Check $c, array $nodes, int $start, int $from, string $mark): bool
    {
        if (!self::escapeMark($c, $nodes, $start, $from, $mark)) {
            return false;
        }
        Check::add($c->closers, $c->last());

        return true;
    }

    /**
     * Asks for a backslash before the first bare `mark` in the text below `nodes`, from the offset `from` of the first
     * node on.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param string $mark
     * @param int $from
     * @param int $start
     */
    public static function escapeMark(Check $c, array $nodes, int $start, int $from, string $mark): bool
    {
        $nodesCount = count($nodes);
        for ($at = $start; $at < $nodesCount; $at++) {
            $node = $nodes[$at];
            if ($node->type !== 'text') {
                if ($node->hasChildren() && self::escapeMark($c, $node->children(), 0, 0, $mark)) {
                    return true;
                }

                continue;
            }
            $value = $node->value ?? '';
            $begin = $at === $start ? $from : 0;
            for ($k = $begin > strlen($value) ? false : strpos($value, $mark, $begin); $k !== false; $k = strpos($value, $mark, $k + 1)) {
                if (self::escapeAt($c, $node, $k)) {
                    return true;
                }
                if ($k + 1 > strlen($value)) {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * The content of an emphasis or strong node ended in the parse at the offset `vi` of `es[i]`: a delimiter in the
     * content closed it. C2-W-069: the braced form holds such content; where the node is braced already, the
     * delimiter takes a backslash (C2-W-093).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param int $vi
     * @param int $i
     */
    private static function closedEarly(Check $c, array $es, int $i, int $vi, Node $owner): void
    {
        $mark = self::CLOSING_MARKS[$owner->type] ?? null;
        if (self::isPaired($owner) && self::hasOtherForm($c, $owner)) {
            self::changeForm($c, $owner);
        } elseif ($mark !== null && self::escapeCloser($c, $es, $i, $vi, $mark)) {
            $c->cut = true;
            if (self::isPaired($owner)) {
                $c->earlyClosers[] = [$owner, $c->last()];
            }
        } else {
            $c->losses[] = [$owner, null];
        }
    }

    /**
     * The start of the line that ended the leaf, from the first line `from` that the parse does not hold. C2-B-102.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $from
     */
    private static function openerLine(array $es, int $from): int
    {
        $at = $from;
        $esCount = count($es);
        while ($at + 2 < $esCount && self::isBraceLine($es[$at]) && $es[$at + 1]->isBreak()) {
            $at += 2;
        }

        return $at;
    }

    private static function isBraceLine(?Node $node): bool
    {
        return $node?->type === 'text' && str_starts_with($node->value ?? '', '{') && str_ends_with($node->value ?? '', '}');
    }

    /**
     * The content of `owner` ended in the parse before the expected node `es[i]`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param int $i
     */
    private static function endedEarly(Check $c, array $es, int $i, Node $owner): void
    {
        $here = $es[$i] ?? null;
        // C2-W-016: a line that holds only a CR, and spaces, is a blank line once the line ending takes the CR.
        $line = $here?->isBreak() === true ? ($es[$i + 1] ?? null) : null;
        if ($line?->type === 'text' && self::endsLineWithCr($line->value ?? '', 0)) {
            $c->losses[] = [$line, 'value'];

            return;
        }
        // C2-W-097: a backslash at the end of the text before took the markup of this node.
        if ($i > 0 && $here?->isBreak() !== true && self::escapeEnd($c, self::lastText($es[$i - 1]), false)) {
            return;
        }
        // C2-W-026, C2-W-092: the content ended at the start of a line, which opened a block.
        if ($i > 0 && $es[$i - 1]->isBreak()) {
            self::escapeLineStart($c, $es, self::openerLine($es, $i), $owner);
        } elseif (isset(self::CLOSING_MARKS[$owner->type])) {
            self::closedEarly($c, $es, $i, 0, $owner);
        } elseif ($here?->isBreak() === true && $i + 1 < count($es)) {
            self::escapeLineStart($c, $es, self::openerLine($es, $i + 1), $owner);
        } else {
            $c->losses[] = [$here ?? $owner, null];
        }
    }

    /**
     * C2-W-125, case six: the escape just placed is of the closing mark that starts the text `es[i]` behind a node.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function markLengthening(Check $c, array $es, int $i): void
    {
        if ($i > 0 && self::startsWithCloser($es[$i - 1], $es[$i] ?? null)) {
            $escape = $c->last();
            Check::add($c->lengthening, $escape);
            if (self::isPaired($es[$i - 1])) {
                Check::add($c->pairedLengthening, $escape);
            }
        }
    }

    /**
     * Steps over the characters that the expected text `e` and the parsed text `a` share at the cursor.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     */
    private static function matchCharacters(Check $c, Node $e, Node $a, Cursor $at, array $es, Node $owner): bool
    {
        $value = $e->value ?? '';
        $other = $a->value ?? '';
        $length = min(strlen($value) - $at->vi, strlen($other) - $at->aj);
        for ($k = 0; $k < $length; $k++) {
            if ($value[$at->vi + $k] === $other[$at->aj + $k]) {
                continue;
            }
            // C2-W-125: behind a content that ended early the parse holds the rest of that content as text.
            if ($c->cut) {
                return false;
            }
            // C2-W-124, C2-W-091: the blanks that start a line are dropped by the parse.
            if (self::isBlank($value[$at->vi + $k]) && self::atLineStart($e, $es, $at->i, $at->vi + $k, $owner)) {
                $c->losses[] = [$e, 'value'];
                $at->vi += $k;
                $at->aj += $k;
                while (self::isBlank($value[$at->vi] ?? '')) {
                    $at->vi++;
                }

                return self::matchCharacters($c, $e, $a, $at, $es, $owner);
            }
            // The parse read a character of the text as markup, such as a backslash before punctuation (C2-W-097).
            if (!self::escapeAt($c, $e, $at->vi + $k)) {
                self::differingCharacter($c, $e, $other, $at, $k);
            } elseif ($at->vi + $k === 0) {
                self::markLengthening($c, $es, $at->i);
            }

            return false;
        }
        $at->vi += max(0, $length);
        $at->aj += max(0, $length);
        if ($at->aj === strlen($other)) {
            $at->j++;
            $at->aj = 0;
        }

        return true;
    }

    /**
     * C2-I-089: a footnote reference shape in a note reads as its source, its backslashes too, so its bracket takes one.
     */
    private static function differingCharacter(Check $c, Node $e, string $other, Cursor $at, int $k): void
    {
        if (($other[$at->aj + $k] ?? '') !== '\\' || !self::escapeNoteReference($c, $e, $at->vi + $k)) {
            $c->losses[] = [$e, 'value'];
        }
    }

    /**
     * C2-I-089: escapes the `[` of the earliest `[^` before `to` in `node` whose label is still open at `to`.
     */
    private static function escapeNoteReference(Check $c, Node $node, int $to): bool
    {
        $value = $node->value ?? '';
        $open = -1;
        for ($at = 0; $at < $to; $at++) {
            $code = $value[$at] ?? '';
            if ($code === '\\') {
                $at++;
            } elseif ($code === ']') {
                $open = -1;
            } elseif ($open < 0 && $code === '[' && ($value[$at + 1] ?? '') === '^' && !self::isEscaped($c, $node, $at)) {
                $open = $at;
            }
        }

        return $open >= 0 && self::escapeAt($c, $node, $open);
    }

    /**
     * The parse ended inside the text node `es[at.i]` of `owner`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function parseEndsInText(Check $c, array $es, Cursor $at, Node $owner): bool
    {
        // C2-W-125: the node before read on into this text; once its repair closes it in place, the text reads as written.
        if ($c->cut) {
            return false;
        }
        $here = $es[$at->i] ?? null;
        // C2-W-016: a CR that ends a line is read as part of the line ending, and no spelling keeps it.
        if ($here?->type === 'text' && self::endsLineWithCr($here->value ?? '', $at->vi)) {
            $c->losses[] = [$here, 'value'];

            return false;
        }
        // C2-W-093: the node before took this text into its content, its mark closing the node in place of the node's own.
        if (
            $here !== null && $at->vi === 0 && $at->i > 0 && self::startsWithCloser($es[$at->i - 1], $here)
            && self::escapeAt($c, $here, 0)
        ) {
            Check::add($c->behind, $c->last());

            return false;
        }
        // C2-W-026, C2-W-092: the content ended at the start of a line, which opened a block.
        if ($at->vi === 0 && $at->i > 0 && $es[$at->i - 1]->isBreak()) {
            self::escapeLineStart($c, $es, self::openerLine($es, $at->i), $owner);
        } elseif (isset(self::CLOSING_MARKS[$owner->type])) {
            self::closedEarly($c, $es, $at->i, $at->vi, $owner);
        } else {
            $c->losses[] = [$owner, null];
        }

        return false;
    }

    /**
     * C2-W-069: a bare emphasis or strong whose content starts or ends with its own mark, unescaped (carve#6469).
     */
    public static function markAtContentEdge(Check $c, Node $owner): bool
    {
        if (!self::isPaired($owner) || !self::hasOtherForm($c, $owner)) {
            return false;
        }
        $mark = self::CLOSING_MARKS[$owner->type];
        $first = $owner->children()[0] ?? null;
        if ($first?->type === 'text' && str_starts_with($first->value ?? '', $mark) && !self::isEscaped($c, $first, 0)) {
            return true;
        }
        $last = self::lastText($owner);

        return $last !== null && str_ends_with($last->value ?? '', $mark)
            && !self::isEscaped($c, $last, strlen($last->value ?? '') - 1);
    }

    /**
     * Whether `next` is text that starts with the closing mark of `node`.
     */
    private static function startsWithCloser(Node $node, ?Node $next): bool
    {
        $mark = self::CLOSING_MARKS[$node->type] ?? null;

        return $mark !== null && $next?->type === 'text' && str_starts_with($next->value ?? '', $mark);
    }

    /**
     * The text node `es[at.i]` is matched to its end; the parse may hold more text behind it.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function afterTextNode(Check $c, array $es, array $as, Cursor $at, Node $owner): bool
    {
        $at->i++;
        $at->vi = 0;
        if ($at->aj === 0 || ($es[$at->i] ?? null)?->type === 'text') {
            return true;
        }
        // The parse has more text here: the node after the text did not form (C2-W-093).
        if (!isset($es[$at->i])) {
            self::readOnPastEnd($c, $owner);
        } elseif (
            $at->j + 1 >= count($as) && self::isPaired($owner) && !self::joinsNext($es, $at->i)
            && !self::holdsSpelling($c, $es[$at->i], $as[$at->j], $at->aj)
        ) {
            self::closedEarly($c, $es, $at->i, 0, $owner);
        } else {
            $c->parsed = $as;
            $c->parsedAt = $at->j;

            return Respell::respell($c, $es, $at->i) && self::stepOver($c, $es, $as, $at);
        }

        return false;
    }

    /**
     * The content of `owner` read on past its end: its closing mark did not close there.
     */
    private static function readOnPastEnd(Check $c, Node $owner): void
    {
        // C2-W-069: a content mark glued to the closer could close the node, so the braced form holds it (carve#6469).
        if (self::markAtContentEdge($c, $owner)) {
            self::changeForm($c, $owner);

            return;
        }
        // C2-W-125: repair a bracketed owner's content pairing first.
        if (isset(self::BRACKETED[$owner->type]) && self::escapeInContent($c, $owner)) {
            $c->cut = true;

            return;
        }
        if (self::escapeOpeningBrace($c, $owner)) {
            Check::add($c->readOn, $c->last());
            $c->cut = true;

            return;
        }
        if (self::escapeInContent($c, $owner) || self::escapeEnd($c, self::lastText($owner), true)) {
            $c->cut = true;

            return;
        }
        // C2-W-069: a closing mark that cannot close where it stands, as behind a space, closes in the braced form.
        if (self::isPaired($owner) && self::hasOtherForm($c, $owner)) {
            self::changeForm($c, $owner);
        } else {
            $c->losses[] = [$owner, null];
        }
    }

    /**
     * C2-W-125, case one: the last brace of the content of `owner` that reads as a braced opener, with its mark behind it.
     */
    public static function escapeOpeningBrace(Check $c, Node $owner): bool
    {
        $text = self::lastText($owner);
        if ($text === null) {
            return false;
        }
        $value = $text->value ?? '';
        $closer = self::CLOSING_MARKS[$owner->type] ?? '';
        for ($at = strrpos($value, '{'); $at !== false;) {
            $next = $at + 1 < strlen($value) ? $value[$at + 1] : $closer;
            if ($next !== '' && str_contains(self::BRACED_MARKS, $next) && !self::isEscaped($c, $text, $at)) {
                return self::escapeAt($c, $text, $at);
            }
            if ($at === 0) {
                break;
            }
            $at = strrpos(substr($value, 0, $at), '{');
        }

        return false;
    }

    /**
     * Whether the text `a` holds the spelling of `e` from `offset` on.
     */
    private static function holdsSpelling(Check $c, Node $e, Node $a, int $offset): bool
    {
        if ($a->type !== 'text') {
            return false;
        }
        $spelled = (string)preg_replace('/\\\\(.)/su', '$1', $c->w->spell->inlines($c->w, [$e], $c->flags));

        return substr($a->value ?? '', $offset, strlen($spelled)) === $spelled;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function matchText(Check $c, Node $e, array $es, array $as, Cursor $at, Node $owner): bool
    {
        $a = $as[$at->j] ?? null;
        if ($a === null) {
            return self::parseEndsInText($c, $es, $at, $owner);
        }
        if ($a->type !== 'text') {
            return self::skipConstruct($c, $e, $es, $a, $at, $owner);
        }
        if (!self::matchCharacters($c, $e, $a, $at, $es, $owner)) {
            return false;
        }

        return $at->vi < strlen($e->value ?? '') || self::afterTextNode($c, $es, $as, $at, $owner);
    }

    /**
     * The node `es[at.i]` did not form, so its markup stands in the text of the parse. Steps over it there (C2-W-091).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    public static function stepOver(Check $c, array $es, array $as, Cursor $at): bool
    {
        $a = $as[$at->j] ?? null;
        $spelled = $c->w->spell->inlines($c->w, [$es[$at->i]], $c->flags);
        // An escape in the node is one character of the text, so the two do not compare by length.
        if ($a?->type !== 'text' || str_contains($spelled, '\\') || substr($a->value ?? '', $at->aj, strlen($spelled)) !== $spelled) {
            return false;
        }
        $at->aj += strlen($spelled);
        if ($at->aj === strlen($a->value ?? '')) {
            $at->j++;
            $at->aj = 0;
        }
        $at->i++;

        return true;
    }

    private static function isBlank(string $char): bool
    {
        return $char === ' ' || $char === "\t";
    }

    /**
     * Whether the offset `at` of `e`, the node `es[i]` of `owner`, starts a line of the written content.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param int $at
     * @param int $i
     */
    private static function atLineStart(Node $e, array $es, int $i, int $at, Node $owner): bool
    {
        if ($at > 0) {
            return (($e->value ?? '')[$at - 1] ?? '') === "\n";
        }

        return $i > 0 ? $es[$i - 1]->isBreak() : $owner->type === 'paragraph';
    }

    /**
     * Where the construct `a` starts in `e`: past the blanks that start a line, except for a comment (C2-I-023).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function constructStart(Node $e, array $es, Cursor $at, Node $a, Node $owner): int
    {
        if ($a->type === 'comment' || !self::atLineStart($e, $es, $at->i, $at->vi, $owner)) {
            return $at->vi;
        }

        return $at->vi + strspn($e->value ?? '', " \t", $at->vi);
    }

    /**
     * C2-I-118: in a group past the depth cap, a bracket construct the parse read over text of the tree opens on the
     * last bracket the reader pushed. Records the opener and the end of the construct for `escapeCappedRun`.
     */
    private static function recordCappedRun(Check $c, Node $e, int $from, int $end, int $shift): void
    {
        if ($c->w->planWhy !== 'depth cap') {
            return;
        }
        $value = $e->value ?? '';
        $stop = $end < 0 ? strlen($value) : $end;
        $at = $from;
        while ($at < $stop && self::isBlank($value[$at])) {
            $at++;
        }
        $at += $shift;
        if (($value[$at] ?? '') === '[' && ($c->marks[spl_object_id($e)][$at] ?? null) === true) {
            $c->capped[] = [$e, $at, $stop];
        }
    }

    /**
     * C2-I-118: where the escape of one capped opener is all a round asks, the escapes behind it are placed in this round.
     */
    public static function escapeCappedRun(Check $c): void
    {
        $capped = $c->capped[0] ?? null;
        $c->capped = [];
        if ($capped === null || count($c->escapes) !== 1 || count($c->retracted) + count($c->forms) > 0) {
            return;
        }
        [$e, $opener, $stop] = $capped;
        if ($c->escapes[0]->node !== $e || $c->escapes[0]->at !== $opener) {
            return;
        }
        $value = $e->value ?? '';
        for ($at = strpos($value, '[', $opener + 1); $at !== false && $at < $stop; $at = strpos($value, '[', $at + 1)) {
            if (self::escapeAt($c, $e, $at)) {
                $c->w->shared->cappedRuns[] = [$e, $at];
            }
        }
    }

    /**
     * C2-I-089: a caret pair that a probe of note content reads, where the note's parse in its context holds none.
     */
    private static function inNoteOnly(Check $c, Node $a): bool
    {
        return $c->note !== null && ($a->type === 'footnote_ref' || $a->type === 'inline_footnote');
    }

    /**
     * Whether the parse in its context reads a bracket construct where the note `e` is written.
     */
    public static function readsBracketConstruct(Check $c, Node $e): bool
    {
        $list = $c->parsed;
        $first = $list[$c->parsedAt] ?? null;
        if ($first === null) {
            return false;
        }
        $stop = ($first->start ?? 0) + self::bracketWindow($e);
        $listCount = count($list);
        for ($at = $c->parsedAt; $at < $listCount; $at++) {
            $node = $list[$at];
            if ($at > $c->parsedAt && ($node->start === null || $node->start >= $stop)) {
                break;
            }
            if (self::holdsBracketConstruct($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Twice the length of the text of `e` and its sigil, which bounds its written form with every character escaped.
     */
    private static function bracketWindow(Node $e): int
    {
        $total = 0;
        foreach (self::textsBelow($e) as $text) {
            $total += strlen($text->value ?? '');
        }

        return 2 * ($total + 3);
    }

    private static function holdsBracketConstruct(Node $node): bool
    {
        $pending = [$node];
        while (($below = array_pop($pending)) !== null) {
            if (isset(self::BRACKET_CONSTRUCTS[$below->type])) {
                return true;
            }
            array_push($pending, ...$below->children());
        }

        return false;
    }

    /**
     * As `readsBracketConstruct` for `es[i]`, with the window behind the text the parse read in its place.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    public static function readsOtherBracketConstruct(Check $c, array $es, int $i): bool
    {
        $list = $c->parsed;
        $first = $list[$c->parsedAt] ?? null;
        if ($first === null) {
            return false;
        }
        $stop = ($first->end ?? 0) + self::bracketWindow($es[$i]);
        $next = $i + 1;
        $listCount = count($list);
        for ($at = $c->parsedAt; $at < $listCount; $at++) {
            $node = $list[$at];
            if ($at > $c->parsedAt && ($node->start === null || $node->start >= $stop)) {
                break;
            }
            // Text of the tree can join the text around it in the parse, so only the other siblings are matched.
            while (($es[$next] ?? null)?->type === 'text') {
                $next++;
            }
            if ($next < count($es) && self::treesEqual($es[$next], $node)) {
                $next++;
            } elseif (self::holdsBracketConstruct($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * carve-js `treesEqual` over mirror nodes.
     */
    public static function treesEqual(Node $a, Node $b): bool
    {
        return TreeEquality::equal($a->toArray(), $b->toArray());
    }

    /**
     * Whether the bare brackets of `e` in `[from, to)` close each other as ordinary brackets.
     */
    private static function bracketsBalance(Check $c, Node $e, int $from, int $to): bool
    {
        $value = $e->value ?? '';
        $depth = 0;
        for ($at = $from; $at < $to; $at++) {
            $char = $value[$at] ?? '';
            if (($char !== '[' && $char !== ']') || self::isEscaped($c, $e, $at)) {
                continue;
            }
            $depth += $char === '[' ? 1 : -1;
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * The escapes `escapes` of a construct's opener: a case-five repair in a note, else an opener, and a read-on brace.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $escapes
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param bool $caret
     */
    private static function keepOpenerEscapes(Check $c, array $escapes, bool $caret, Node $owner): void
    {
        foreach ($escapes as $escape) {
            if ($caret) {
                Check::add($c->closers, $escape);
            } else {
                Check::add($c->constructs, $escape);
            }
            if (!$caret && self::readsIntoCloser($owner, $escape)) {
                Check::add($c->readOn, $escape);
            }
        }
    }

    /**
     * C2-W-125, case one, C2-W-088: the brace that ends the content of `owner` reads with its closing mark as a braced
     * opener, so `owner` reads on.
     */
    private static function readsIntoCloser(Node $owner, Escape $escape): bool
    {
        $value = $escape->node->value ?? '';

        return ($value[$escape->at] ?? '') === '{' && $escape->at === strlen($value) - 1
            && self::lastText($owner) === $escape->node && !isset(self::BRACKETED[$owner->type])
            && isset(self::CLOSING_MARKS[$owner->type]);
    }

    /**
     * The parse read a construct where the tree has text: escapes its opener and steps over it (C2-W-091).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     */
    private static function skipConstruct(Check $c, Node $e, array $es, Node $a, Cursor $at, Node $owner): bool
    {
        // C2-W-125: behind a content that ended early, the parse can open a construct on the markup of the tree.
        if ($c->cut) {
            return false;
        }
        $quoted = Smart::comparisonBehindQuote($es[$at->i - 2] ?? null, $es[$at->i - 1] ?? null, $e, $at->vi, $a);
        $end = $a->start === null || $a->end === null
            ? -1
            : $c->w->spell->spelledEnd($c->w, $e, self::constructStart($e, $es, $at, $a, $owner), $a->end - $a->start, $c->flags);
        $caret = self::inNoteOnly($c, $a);
        $before = count($c->escapes);
        // C2-I-089: in the note a caret pair opens nothing and its brackets are ordinary (C2-W-088).
        if ($caret && $end >= 0 && self::bracketsBalance($c, $e, $at->vi, $end)) {
            // nothing to escape
        } elseif ($quoted) {
            self::escapeAt($c, $e, $at->vi + 1);
        } else {
            self::escapeOpener($c, $es, $at->i, $at->vi, $a);
        }
        self::keepOpenerEscapes($c, array_slice($c->escapes, $before), $caret, $owner);
        if ($a->start === null) {
            return false;
        }
        if (!$quoted) {
            self::recordCappedRun($c, $e, $at->vi, $end, self::openerShift($a));
        }
        if ($end < 0) {
            Smart::escapeInnerQuotes($c, $es, $at->i, $at->vi, $a);

            return false;
        }
        $at->vi = $end;
        $at->j++;
        if ($at->vi === strlen($e->value ?? '')) {
            $at->i++;
            $at->vi = 0;
        }

        return true;
    }

    /**
     * Whether the parse read `es[i]` differently because of the text beside it, which then takes a backslash.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param int $i
     */
    private static function readsNeighbor(Check $c, Node $e, array $es, int $i, Node $a): bool
    {
        $next = self::textAt($es, $i + 1);
        if (
            $next !== null && $a->field('attrs') !== null && !self::sameValue($e->field('attrs'), $a->field('attrs'))
            && str_starts_with($next->value ?? '', '{')
        ) {
            return self::escapeAt($c, $next, 0);
        }
        // C2-I-015, C2-I-090: a backtick behind a closing run, a name character behind a mention or a tag.
        if ($next !== null && self::readsOn($e, $next) && self::differingField($c, $e, $a) !== null) {
            if (!self::escapeAt($c, $next, 0)) {
                return false;
            }
            Check::add($c->lengthening, $c->last());

            return true;
        }
        if (
            $e->type !== 'smart_punctuation' || $a->type !== 'smart_punctuation'
            || ($e->field('kind') === $a->field('kind') && $e->field('source') === $a->field('source'))
        ) {
            return false;
        }

        return Smart::escapeSmartSideAfterConstruct($c, $es, $i, $next);
    }

    /**
     * Whether the text `next` can read on into the end of the spelling of `node`: a closing backtick run, or a name.
     */
    private static function readsOn(Node $node, Node $next): bool
    {
        if ($node->field('attrs') !== null) {
            return false;
        }
        if ($node->type === 'mention' || $node->type === 'tag') {
            return true;
        }

        return isset(['code' => 1, 'literal_inline' => 1, 'math' => 1][$node->type]) && str_starts_with($next->value ?? '', '`');
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $at
     */
    public static function textAt(array $nodes, int $at): ?Node
    {
        $node = $nodes[$at] ?? null;

        return $node?->type === 'text' ? $node : null;
    }

    private static function isLostCode(Check $c, ?Node $node): bool
    {
        return $node !== null && $node->type === 'code' && $c->w->isLost($node);
    }

    /**
     * Compares an expected node that is no text with the parse. Returns false where the lists cannot be compared further.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function matchNode(Check $c, Node $e, array $es, array $as, Cursor $at, Node $owner): bool
    {
        $a = $as[$at->j] ?? null;
        if ($a === null) {
            self::endedEarly($c, $es, $at->i, $owner);

            return false;
        }
        if ($a->type !== $e->type || $at->aj > 0) {
            // C2-W-125: behind a content that ended early the parse reads its neighbors otherwise.
            if ($c->cut) {
                return false;
            }
            $c->parsed = $as;
            $c->parsedAt = $at->j;
            if (Respell::respell($c, $es, $at->i)) {
                return self::stepOver($c, $es, $as, $at);
            }

            return self::stepOverLost($c, $e, $es, $as, $at);
        }
        if (self::isLostCode($c, $es[$at->i + 1] ?? null)) {
            // C2-W-112: the code spans behind this one were written into it and reported.
            $at->i++;
            while (self::isLostCode($c, $es[$at->i] ?? null)) {
                $at->i++;
            }
            $at->j++;

            return true;
        }
        if (self::readsNeighbor($c, $e, $es, $at->i, $a)) {
            return false;
        }
        self::repairCommentCloser($c, $es, $at->i, $e, $a);
        $outer = $c->cut;
        $asked = $c->changes();
        $c->cut = false;
        $c->around[] = [$es, $at->i, $c->flags];
        self::compareInline($c, $e, $a);
        array_pop($c->around);
        // C2-W-125: a content that read on or ended early was repaired inside; behind it the parse is read again next round.
        if ($c->changes() > $asked && isset(self::BRACKETED[$e->type]) && self::extentDiffers($c, $e, $a)) {
            $c->cut = true;
        }
        $c->cut = $c->cut || $outer;
        $at->i++;
        $at->j++;

        return isset(self::VERBATIM[$e->type]) && self::differingField($c, $e, $a) !== null
            ? self::stepOverAbsorbed($c, $e, $es, $as, $a, $at)
            : true;
    }

    /**
     * Whether the parse node `a` spans other bytes than the spelling of `e` it was read from, on one line.
     */
    private static function extentDiffers(Check $c, Node $e, Node $a): bool
    {
        $own = $c->w->spell->inlines($c->w, [$e], $c->flags);

        return $a->start !== null && $a->end !== null && !str_contains($own, "\n") && $a->end - $a->start !== strlen($own);
    }

    /**
     * C2-W-117, C2-W-124: the span `a` read on past the spelling of `e` to a later closing run.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     */
    private static function stepOverAbsorbed(Check $c, Node $e, array $es, array $as, Node $a, Cursor $at): bool
    {
        if ($a->end === null || $a->start === null) {
            return true;
        }
        $own = $c->w->spell->inlines($c->w, [$e], $c->flags);
        // Container prefixes on later lines would stand between the offsets.
        if (str_contains($own, "\n")) {
            return true;
        }
        $taken = new Taken($a->start + strlen($own), 0, '');

        return self::loseInto($c, $es, $as, $at, $taken, $a->end);
    }

    /**
     * C2-W-117, C2-W-124: the verbatim node `e` read back as no node of its kind, and a span of the parse that starts
     * inside its spelling reads on past it.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function stepOverLost(Check $c, Node $e, array $es, array $as, Cursor $at): bool
    {
        if (!isset(self::VERBATIM[$e->type]) || $at->anchor !== $at->i) {
            return false;
        }
        $own = $c->w->spell->inlines($c->w, [$e], $c->flags);
        if (str_contains($own, "\n") || !self::spelledAt($c, $at->anchorByte, $own)) {
            return false;
        }
        $offset = $at->anchorByte + strlen($own);
        $j = $at->j;
        while (isset($as[$j]) && ($as[$j]->end ?? PHP_INT_MAX) <= $offset) {
            $j++;
        }
        $reader = $as[$j] ?? null;
        $from = $reader->start ?? $offset;
        // A span that starts where `e` starts is `e` read with another closer (C2-W-112), not a span inside it.
        if ($reader === null || $from <= $at->anchorByte || $from >= $offset || !isset(self::VERBATIM[$reader->type])) {
            return false;
        }
        $at->i++;
        $at->j = $j + 1;
        $at->aj = 0;

        return self::loseInto($c, $es, $as, $at, new Taken($offset, 0, ''), $reader->end ?? $offset);
    }

    /**
     * Whether the written group holds `spelled` at the byte `offset`.
     */
    private static function spelledAt(Check $c, int $offset, string $spelled): bool
    {
        return $c->source !== null && $offset >= 0 && substr($c->source, $offset, strlen($spelled)) === $spelled;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param int $end
     * @param \MarkupCarve\Carve\Writer\Check\Taken $taken
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function loseInto(Check $c, array $es, array $as, Cursor $at, Taken $taken, int $end): bool
    {
        for (;;) {
            self::loseBefore($c, $es, $at, $taken, $end);
            if ($taken->offset < $end) {
                return false;
            }
            $next = self::leftoverNode($as, $at, $taken->offset);
            if ($next === null) {
                return true;
            }
            if ($next->type === 'text') {
                $rest = substr($taken->spelled, max(0, ($next->start ?? 0) - $taken->start));

                return self::stepIntoLeftover($at, $next, $rest, $taken->offset);
            }
            $end = $next->end ?? $end;
            $at->j++;
        }
    }

    /**
     * The parse node that starts inside the leftover of the last lost node and runs on past it, or null.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param int $offset
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function leftoverNode(array $as, Cursor $at, int $offset): ?Node
    {
        // The parse nodes that lie inside the leftover belong to the lost node.
        while (isset($as[$at->j]) && ($as[$at->j]->end ?? PHP_INT_MAX) <= $offset) {
            $at->j++;
        }
        $from = ($as[$at->j] ?? null)?->start;

        return $from !== null && $from < $offset ? $as[$at->j] : null;
    }

    /**
     * Loses the siblings from the cursor on whose spelling starts before `end`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $end
     * @param \MarkupCarve\Carve\Writer\Check\Taken $taken
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function loseBefore(Check $c, array $es, Cursor $at, Taken $taken, int $end): void
    {
        $esCount = count($es);
        while ($taken->offset < $end && $at->i < $esCount && !$es[$at->i]->isBreak()) {
            $to = self::gluedEnd($es, $at->i);
            $taken->spelled = $c->w->spell->inlines($c->w, array_slice($es, $at->i, $to - $at->i), $c->flags);
            $taken->start = $taken->offset;
            $taken->offset += strlen($taken->spelled);
            for (; $at->i < $to; $at->i++) {
                $c->losses[] = [$es[$at->i], $es[$at->i]->type === 'text' ? 'value' : null];
            }
        }
    }

    /**
     * C2-W-112: the end of the part that starts at `from`, where code spans glued to each other are written as one.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $from
     */
    private static function gluedEnd(array $es, int $from): int
    {
        $to = $from + 1;
        while (self::joinsNextCode($es, $to - 1)) {
            $to++;
        }

        return $to;
    }

    /**
     * carve-js `joinsNextCode` (write-inline.ts): a code span without attributes glued to the next code span.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $at
     */
    private static function joinsNextCode(array $nodes, int $at): bool
    {
        $node = $nodes[$at] ?? null;

        return $node?->type === 'code' && $node->field('attrs') === null && ($nodes[$at + 1] ?? null)?->type === 'code';
    }

    /**
     * The parsed text `text` starts with `tail`, the end of a lost node; the comparison goes on behind it.
     */
    private static function stepIntoLeftover(Cursor $at, Node $text, string $tail, int $offset): bool
    {
        if (!str_starts_with($text->value ?? '', $tail)) {
            return false;
        }
        // The next sibling starts where the lost one ends, inside the parsed text.
        $at->anchor = $at->i;
        $at->anchorByte = $offset;
        $at->aj = strlen($tail);
        if ($at->aj === strlen($text->value ?? '')) {
            $at->j++;
            $at->aj = 0;
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $actual
     * @param \MarkupCarve\Carve\Writer\Check\Node $expected
     * @param int $at
     */
    private static function repairCommentCloser(Check $c, array $es, int $at, Node $expected, Node $actual): void
    {
        if ($expected->type !== 'comment' || $actual->type !== 'comment' || $expected->field('form') !== 'line') {
            return;
        }
        $want = $expected->value ?? '';
        $have = $actual->value ?? '';
        if (!str_contains($want, ']') && str_contains($have, ']')) {
            if (!self::repairBracketBeforeComment($c, $es, $at)) {
                self::repairBraceBeforeComment($c, $es, $at);
            }

            return;
        }
        if (!str_contains($want, ']') || str_contains($have, ']')) {
            return;
        }
        $before = $es[$at - 1] ?? null;
        if ($before?->type !== 'text') {
            return;
        }
        foreach (self::bracketsBeforeComment($before->value ?? '') as $bracket) {
            if (self::escapeAt($c, $before, $bracket)) {
                return;
            }
        }
    }

    /**
     * The adjacent open braces remain text when the final bracket or caret keeps the comment inside its bracket.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $at
     */
    private static function repairBracketBeforeComment(Check $c, array $es, int $at): bool
    {
        $before = $es[$at - 1] ?? null;
        $value = $before->value ?? '';
        if ($before?->type !== 'text' || !str_contains($value, '[{*{*')) {
            return false;
        }
        $open = strrpos($value, '[');
        if (str_contains(substr($value, $open === false ? 0 : $open), '{*')) {
            return false;
        }
        $last = strlen($value) - 1;

        return ($value[$last] === ']' || $value[$last] === '^') && self::escapeAt($c, $before, $last);
    }

    /**
     * @return list<int>
     */
    private static function bracketsBeforeComment(string $value): array
    {
        $open = [];
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            if ($value[$index] === '[') {
                $open[] = $index;
            } elseif ($value[$index] === ']' && $open !== []) {
                array_pop($open);
            }
        }

        return $open;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $at
     */
    private static function repairBraceBeforeComment(Check $c, array $es, int $at): void
    {
        for ($index = $at - 1; $index >= 0; $index--) {
            $node = $es[$index];
            if ($node->isBreak()) {
                return;
            }
            if ($node->type !== 'text') {
                continue;
            }
            $value = $node->value ?? '';
            for ($brace = strlen($value) - 2; $brace >= 0; $brace--) {
                if ($value[$brace] === '{' && str_contains('*/-=', $value[$brace + 1]) && self::escapeAt($c, $node, $brace)) {
                    return;
                }
            }
        }
    }

    /**
     * Notes the place of the cursor where both lists start a node there. A line break ends the line the offsets hold on.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function setAnchor(array $es, array $as, Cursor $at): void
    {
        if ($at->vi !== 0 || $at->aj !== 0) {
            return;
        }
        $start = ($as[$at->j] ?? null)?->start;
        if (($es[$at->i] ?? null)?->isBreak() === true) {
            $at->anchor = -1;
        } elseif ($start !== null) {
            $at->anchor = $at->i;
            $at->anchorByte = $start;
        }
    }

    /**
     * The first parsed node from `j` on that starts at `offset` or later.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param int $offset
     * @param int $j
     */
    private static function firstNodeFrom(array $as, int $j, int $offset): int
    {
        $asCount = count($as);
        while ($j < $asCount && ($as[$j]->start ?? -1) < $offset) {
            $j++;
        }

        return $j;
    }

    private static function resumeAt(Cursor $at, int $i, int $j): bool
    {
        $at->i = $i;
        $at->j = $j;
        $at->vi = 0;
        $at->aj = 0;

        return true;
    }

    /**
     * After the two lists could not be compared further: moves the cursor to the next node of the expected list at
     * whose start, on the same line, a node of the parse starts too (C2-W-091). False where there is no such place.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function resync(Check $c, array $es, array $as, Cursor $at): bool
    {
        $failedEnd = ($as[$at->j] ?? null)?->end;
        if ($at->anchor < 0 || $failedEnd === null || ($as[$at->j]->start === null)) {
            return false;
        }
        $offset = $at->anchorByte;
        $j = $at->j;
        $esCount = count($es);
        for ($k = $at->anchor; $k < $esCount && !$es[$k]->isBreak(); $k++) {
            $j = self::firstNodeFrom($as, $j, $offset);
            if ($k > $at->i && $offset >= $failedEnd && ($as[$j] ?? null)?->start === $offset) {
                return self::resumeAt($at, $k, $j);
            }
            // The node alone, not as a list of one: a list places escapes for its end, which would land in the real writer.
            $own = $c->w->spell->inline($c->w, $es[$k], $c->flags);
            // Container prefixes on later lines would stand between the offsets.
            if (str_contains($own, "\n") && !self::spelledAt($c, $offset, $own)) {
                return false;
            }
            $offset += strlen($own);
        }

        return false;
    }

    private static function hasAuthoredTextFields(Node $node): bool
    {
        foreach (['lang', 'dir', 'metadata', 'attrs', 'data'] as $name) {
            if ($node->field($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A leading space or tab of supplied text can be lost at a paragraph start while the rest of the text joins the
     * next node in the read-back parse. Continue at the surviving suffix (C2-W-117).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function stepOverLostText(Check $c, array $es, array $as, Cursor $at): bool
    {
        $expected = $es[$at->i] ?? null;
        $actual = $as[$at->j] ?? null;
        if ($expected?->type !== 'text' || $actual?->type !== 'text' || $at->vi !== 0) {
            return false;
        }
        $kept = ltrim($expected->value ?? '', " \t");
        if (strlen($kept) === strlen($expected->value ?? '') || substr($actual->value ?? '', $at->aj, strlen($kept)) !== $kept) {
            return false;
        }
        if (self::hasAuthoredTextFields($expected)) {
            $last = count($c->losses) - 1;
            if ($last >= 0 && $c->losses[$last][0] === $expected) {
                $c->losses[$last] = [$expected, ''];
            }
        }
        $at->i++;
        $at->aj += strlen($kept);
        if ($at->aj === strlen($actual->value ?? '')) {
            $at->j++;
            $at->aj = 0;
        }

        return true;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $from
     */
    private static function nextBreak(array $nodes, int $from): int
    {
        $nodesCount = count($nodes);
        for ($at = $from; $at < $nodesCount; $at++) {
            if ($nodes[$at]->isBreak()) {
                return $at;
            }
        }

        return count($nodes);
    }

    /**
     * The line ends in the spelling of `es` before `es[end]`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $end
     */
    private static function writtenBreaks(Check $c, array $es, int $end): int
    {
        $breaks = 0;
        for ($k = 0; $k < $end; $k++) {
            $breaks += substr_count($c->w->spell->inline($c->w, $es[$k], $c->flags), "\n");
        }

        return $breaks;
    }

    /**
     * A failed construct on one line cannot hide constructs on following lines of the same paragraph.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Cursor $at
     */
    private static function resyncNextConstructLine(Check $c, array $es, array $as, Cursor $at): bool
    {
        if (($es[$at->i] ?? null)?->type === 'text') {
            return false;
        }
        $i = self::nextBreak($es, $at->i + 1);
        $j = self::nextBreak($as, $at->j);
        if ($i === count($es) || $j === count($as) || $es[$i]->type !== $as[$j]->type) {
            return false;
        }
        $first = $as[0]->line ?? null;
        $actual = $as[$j]->line;
        // The line is read from the written spelling: the positions of the tree are those of its source.
        if ($first === null || $actual === null || $actual - $first !== self::writtenBreaks($c, $es, $i)) {
            return false;
        }
        if ($c->changes() === 0) {
            for ($k = $at->i; $k < $i; $k++) {
                $c->losses[] = [$es[$k], null];
            }
        }

        return self::resumeAt($at, $i + 1, $j + 1);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     */
    public static function compareInlines(Check $c, array $es, array $as, Node $owner): void
    {
        $outer = $c->owner;
        $c->owner = $owner;
        self::compareList($c, $es, $as, $owner);
        $c->owner = $outer;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     */
    private static function compareList(Check $c, array $es, array $as, Node $owner): void
    {
        $at = new Cursor();
        $esCount = count($es);
        while ($at->i < $esCount) {
            self::setAnchor($es, $as, $at);
            $e = $es[$at->i];
            $more = $e->type === 'text'
                ? self::matchText($c, $e, $es, $as, $at, $owner)
                : self::matchNode($c, $e, $es, $as, $at, $owner);
            if (
                !$more && !self::resync($c, $es, $as, $at) && !self::stepOverLostText($c, $es, $as, $at)
                && !self::resyncNextConstructLine($c, $es, $as, $at)
            ) {
                return;
            }
        }
        if ($at->j >= count($as)) {
            return;
        }
        // C2-W-005, C2-W-069: the content read on past its end, as its closer paired later, so the node takes the
        // braced form in every round, the kind rounds included (carve#6482).
        if (self::isPaired($owner) && self::hasOtherForm($c, $owner)) {
            self::changeForm($c, $owner);
        } elseif (!self::escapeUnclosedBrackets($c, $owner)) {
            $c->losses[] = [$owner, null];
        }
    }

    /**
     * A C2-W-125 bracket repair: its backslash gives no kind (C2-W-088), as that of a closer.
     */
    public static function escapeBracket(Check $c, Node $text, int $at): bool
    {
        if (!self::escapeAt($c, $text, $at)) {
            return false;
        }
        Check::add($c->closers, $c->last());

        return true;
    }

    /**
     * C2-W-125, case five: a bare `[` of the content of a bracketed node that no bare `]` of that content closes pairs
     * with the closer of the node, so the node reads on past its end. Each such `[` takes the backslash.
     */
    public static function escapeUnclosedBrackets(Check $c, Node $owner): bool
    {
        if (!isset(self::BRACKETED[$owner->type])) {
            return false;
        }
        $open = [];
        $inner = null;
        $bare = static fn (Node $node, int $offset): bool => !self::isEscaped($c, $node, $offset);
        self::contentClosers($owner->children(), $bare, $inner, $open);
        $asked = false;
        foreach ($open as [$text, $at]) {
            $asked = self::escapeBracket($c, $text, $at) || $asked;
        }
        // What the node took from behind its end is read again once it closes in place.
        if ($asked) {
            $c->cut = true;
        }

        return $asked;
    }

    /**
     * Compares a node that the writer took from the sidecar as it was typed: any difference sends it back to the tree form.
     */
    private static function compareRaw(Check $c, Node $e, Node $a): void
    {
        $probe = new Check($c->w);
        $probe->flags = $c->flags;
        self::compareFields($probe, $e, $a);
        if ($e->hasChildren()) {
            self::compareInlines($probe, $e->children(), $a->children(), $e);
        }
        // A difference behind another change of this round may come from that change, so the next round decides.
        if ($probe->changes() + count($probe->losses) > 0 && $c->changes() === 0) {
            self::changeForm($c, $e);
        } elseif ($probe->changes() + count($probe->losses) > 0) {
            $c->misaligned = true;
        }
    }

    private static function compareFields(Check $c, Node $e, Node $a): void
    {
        $field = self::differingField($c, $e, $a);
        if ($field !== null) {
            $c->losses[] = [$e, $field];
        }
    }

    /**
     * carve-js `contentFlag`: the flags the content of a node of `$type` is written with.
     */
    public static function contentFlag(string $type): int
    {
        return match ($type) {
            'emphasis' => Spell::IN_EMPHASIS | Spell::AT_PAIRED_END,
            'strong' => Spell::IN_STRONG | Spell::AT_PAIRED_END,
            'link' => Spell::IN_BRACKET,
            'inline_footnote' => Spell::IN_NOTE,
            default => 0,
        };
    }

    public static function contentFlags(Check $c, Node $e): int
    {
        return ($c->flags & ~Spell::AT_PAIRED_END) | self::contentFlag($e->type);
    }

    private static function compareInline(Check $c, Node $e, Node $a): void
    {
        if (self::sourceCoreEquivalent($e, $a)) {
            return;
        }
        if ($e->field('key') !== null && self::hasOtherForm($c, $e)) {
            self::compareRaw($c, $e, $a);

            return;
        }
        self::compareFields($c, $e, $a);
        if (!$e->hasChildren()) {
            return;
        }
        $outer = $c->flags;
        $c->flags = self::contentFlags($c, $e);
        self::compareInlines($c, $e->children(), $a->children(), $e);
        $c->flags = $outer;
    }

    /**
     * carve-js `sourceCoreEquivalent` (write-extension-source.ts). Extension source forms are not ported yet, so no
     * node is equivalent through them.
     */
    private static function sourceCoreEquivalent(Node $e, Node $a): bool
    {
        return false;
    }

    /**
     * The block `e` read back as a block of another type.
     */
    private static function blockMismatch(Check $c, Node $e): void
    {
        $c->misaligned = true;
        // C2-W-092: the first line of a paragraph read as a block opener.
        if ($e->type === 'paragraph' && $e->children() !== []) {
            self::escapeLineStart($c, $e->children(), 0, $e);
        } else {
            $c->losses[] = [$e, null];
        }
    }

    /**
     * The block `e` has no block in the parse: the block above it took its lines.
     */
    private static function missingBlock(Check $c, Node $e): void
    {
        // C2-W-016: a paragraph whose line holds only a CR, and spaces, is a blank line once the line ending takes the CR.
        $first = $e->type === 'paragraph' ? ($e->children()[0] ?? null) : null;
        if ($first?->type === 'text' && self::endsLineWithCr($first->value ?? '', 0)) {
            $c->losses[] = [$first, 'value'];

            return;
        }
        // C2-W-092: a paragraph that reads as a line of the block above. C2-W-059: a comment stands behind a blank line.
        if ($e->type === 'paragraph' && $e->children() !== []) {
            self::escapeLineStart($c, $e->children(), 0, $e);
        } elseif (self::hasOtherForm($c, $e)) {
            self::changeForm($c, $e);
        } else {
            $c->losses[] = [$e, null];
        }
    }

    private static function hasSpanMarks(Node $table): bool
    {
        foreach ($table->children() as $group) {
            if ($group->type === 'comment') {
                continue;
            }
            foreach ($group->children() as $row) {
                if ($row->type !== 'table_row') {
                    continue;
                }
                foreach ($row->children() as $cell) {
                    if ($cell->field('span') !== null) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function compareSlots(Check $c, Node $e, Node $a): void
    {
        $title = $e->slots['title'] ?? null;
        $parsedTitle = $a->slots['title'] ?? null;
        if ($title !== null) {
            self::compareInlines($c, $title, $parsedTitle ?? [], $e);
        } elseif ($parsedTitle !== null) {
            $c->losses[] = [$e, 'title'];
        }
        $caption = $e->slots['caption'][0] ?? null;
        $parsedCaption = $a->slots['caption'][0] ?? null;
        if ($caption !== null && $parsedCaption !== null) {
            self::compareBlock($c, $caption, $parsedCaption);
        } elseif ($caption !== null || $parsedCaption !== null) {
            $c->losses[] = [$caption ?? $e, $caption === null ? 'caption' : null];
        }
    }

    /**
     * Compares one block with its parse. Returns false where the blocks behind it cannot be compared.
     */
    private static function compareBlock(Check $c, Node $e, Node $a, bool $absorbed = false): bool
    {
        if ($c->w->isLost($e) || self::sourceCoreEquivalent($e, $a)) {
            return true;
        }
        if ($e->type !== $a->type) {
            // A line-block line can be read as a comment fence instead of as text.
            if ($e->type === 'line' && $a->type === 'comment') {
                self::escapeLineStart($c, $e->children(), 0, $e);
            } else {
                self::blockMismatch($c, $e);
            }

            return false;
        }
        $marks = $c->spanMarks;
        $flags = $c->flags;
        if ($e->type === 'table') {
            $c->spanMarks = self::hasSpanMarks($e) || self::hasSpanMarks($a);
        }
        if ($e->type === 'table_cell') {
            $c->flags |= Spell::IN_CELL;
        }
        self::compareFields($c, $e, $a);
        // A content that ended early in another block keeps nothing of this one from being compared.
        $c->cut = false;
        if (self::isInlineParent($e)) {
            self::compareInlines($c, $e->children(), $a->children(), $e);
        } elseif ($e->type !== 'include') {
            // C2-N-081: the children of an include are a result of its expansion, which the source does not hold.
            self::compareBlocks($c, $e->children(), $a->children(), $e, $absorbed);
        }
        self::compareSlots($c, $e, $a);
        $c->spanMarks = $marks;
        $c->flags = $flags;

        return true;
    }

    /**
     * Records the start of `block`, in a container that starts on the line `container` (carve-js `Starts`: paragraphs
     * by place, other blocks by column, and the line of the container of each paragraph).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $block
     * @param int $offset
     * @param int $container
     * @param array{paragraphs: array<int, true>, columns: array<int, array<int, true>>, containers: array<int, int>} $starts
     */
    private static function readStart(Node $block, int $offset, int $container, array &$starts): void
    {
        if ($block->line === null || $block->column === null) {
            return;
        }
        $line = $block->line - $offset;
        if ($block->type === 'paragraph') {
            $starts['containers'][$line] = $container;
            $starts['paragraphs'][$line * WriterState::PLACE_COLUMNS + $block->column - 1] = true;

            return;
        }
        $starts['columns'][$line][$block->column - 1] = true;
    }

    /**
     * The starts of the blocks below the parse `node`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param int $offset
     * @param array{paragraphs: array<int, true>, columns: array<int, array<int, true>>, containers: array<int, int>} $starts
     */
    private static function readStarts(Node $node, int $offset, array &$starts): void
    {
        $container = $node->line === null ? -1 : $node->line - $offset;
        foreach ($node->children() as $block) {
            // C2-W-088: a row has the line kind `|`; its cells open nothing at the start of a line.
            if ($block->type === 'table_cell') {
                continue;
            }
            self::readStart($block, $offset, $container, $starts);
            if (!self::isInlineParent($block)) {
                self::readStarts($block, $offset, $starts);
            }
            $caption = $block->slots['caption'][0] ?? null;
            if ($caption !== null) {
                self::readStarts($caption, $offset, $starts);
            }
        }
    }

    /**
     * Whether the text at the start of a line has the shape of a block opener.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $from
     */
    private static function hasOpenerShape(array $nodes, int $from): bool
    {
        $node = $nodes[$from] ?? null;
        $value = self::joinedValue(self::leadingTexts($nodes, $from));
        if ($value === '') {
            return $node !== null && $node->type !== 'text';
        }
        // A pipe needs another pipe to close a row (C2-B-166); one pipe opens no block.
        if ($value[0] === '|' && strpos($value, '|', 1) === false) {
            return false;
        }

        return !self::isAsciiAlphanumeric($value[0]) || count(self::openerOffsets($value)) > 1;
    }

    /**
     * C2-W-090, C2-W-099: the character that takes the backslash of a block that starts at `at`.
     */
    private static function nestedOpener(string $value, int $at): int
    {
        if (substr($value, $at, 2) === '--') {
            return $at + 1;
        }
        $end = $at;
        $valueLength = strlen($value);
        while ($end < $valueLength && self::isAsciiAlphanumeric($value[$end])) {
            $end++;
        }

        return $end;
    }

    /**
     * Whether the escapes of this round already touch `node`.
     */
    public static function isPending(Check $c, ?Node $node): bool
    {
        return $node?->type === 'text' && isset($c->marks[spl_object_id($node)]);
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * Escapes the opener of each line of the paragraph `e`, written at `place`, at which the parse has a block.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param int $place
     * @param array{paragraphs: array<int, true>, columns: array<int, array<int, true>>, containers: array<int, int>} $starts
     */
    private static function escapeOpenerLines(Check $c, Node $e, int $place, array $starts): void
    {
        $top = $e->children();
        if (!isset($starts['paragraphs'][$place]) && self::hasOpenerShape($top, 0) && !self::isPending($c, $top[0] ?? null)) {
            self::escapeLineStart($c, $top, 0, $e);
        }
        $line = intdiv($place, WriterState::PLACE_COLUMNS);
        $firstLine = $line;
        $nested = static function (?Node $node) use ($c, &$line, $firstLine, $place, $starts): void {
            if ($node?->type !== 'text') {
                return;
            }
            $value = $node->value ?? '';
            $written = $c->w->lines[$line] ?? '';
            // The text stands behind the prefix of its line, which can hold the same characters, as the markers of `- - -`.
            $offset = $line === $firstLine ? $place % WriterState::PLACE_COLUMNS : 0;
            $from = $value === '' || $offset > strlen($written) ? false : strpos($written, $value, $offset);
            $start = $from !== false ? $from : ($value === '' ? false : strpos($written, $value));
            if ($start === false) {
                return;
            }
            foreach (array_keys($starts['columns'][$line] ?? []) as $column) {
                $at = $column - $start < 0 ? -1 : self::nestedOpener($value, $column - $start);
                if ($at >= 0 && $at < strlen($value) && self::escapeAt($c, $node, $at)) {
                    $c->ownEscapes[spl_object_id($node)][$at] = true;
                }
            }
        };
        $nested($top[0] ?? null);
        self::visitOpenerLines($c, $top, $line, $firstLine, $starts, $e, $nested);
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * The walk of `escapeOpenerLines` over the inlines `nodes`: each line break moves to the next line.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $firstLine
     * @param int $line
     * @param array{paragraphs: array<int, true>, columns: array<int, array<int, true>>, containers: array<int, int>} $starts
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node|null): void $nested
     */
    private static function visitOpenerLines(
        Check $c,
        array $nodes,
        int &$line,
        int $firstLine,
        array $starts,
        Node $e,
        Closure $nested,
    ): void {
        foreach ($nodes as $at => $node) {
            if ($node->hasChildren()) {
                self::visitOpenerLines($c, $node->children(), $line, $firstLine, $starts, $e, $nested);
            }
            if (!$node->isBreak()) {
                continue;
            }
            $line++;
            $opens = self::opensOn($starts, $line, $firstLine) && self::hasOpenerShape($nodes, $at + 1);
            if ($opens && !self::isPending($c, $nodes[$at + 1] ?? null)) {
                self::escapeLineStart($c, $nodes, $at + 1, $e);
            }
            $nested($nodes[$at + 1] ?? null);
        }
    }

    /**
     * Whether the parse starts a block on `line` of a paragraph whose first line is `first`.
     *
     * @param array{paragraphs: array<int, true>, columns: array<int, array<int, true>>, containers: array<int, int>} $starts
     * @param int $first
     * @param int $line
     */
    private static function opensOn(array $starts, int $line, int $first): bool
    {
        if (isset($starts['columns'][$line])) {
            return true;
        }
        $container = $starts['containers'][$line] ?? null;

        return $container !== null && $container > $first;
    }

    /**
     * After a block read back as another block, the blocks behind it are not compared one by one. This finds the
     * other lines of the group that opened a block, by the lines at which the parse `parsed` has one (C2-W-092).
     */
    public static function escapeOpeners(Check $c, Node $parsed, int $offset): void
    {
        if (!$c->misaligned) {
            return;
        }
        $starts = ['paragraphs' => [], 'columns' => [], 'containers' => []];
        self::readStarts($parsed, $offset, $starts);
        foreach ($c->w->starts as [$node, $place]) {
            if ($node->field('attrs') === null && $node->field('lang') === null) {
                self::escapeOpenerLines($c, $node, $place, $starts);
            }
        }
    }

    /**
     * Compares the blocks `es` with their parse `as` in order. Returns false where the blocks behind cannot be compared.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param bool $absorbed
     */
    private static function compareInOrder(Check $c, array $es, array $as, bool $absorbed): bool
    {
        $read = 0;
        $esCount = count($es);
        $asCount = count($as);
        for ($at = 0; $at < $esCount && $read < $asCount; $at++) {
            // A lost block that reads as the last block of the one above is reported once.
            $takes = $at === count($es) - 1
                ? $absorbed
                : count($es) - $at > count($as) - $read && $c->w->isLost($es[$at + 1]);
            if (!self::compareBlock($c, $es[$at], $as[$read++], $takes)) {
                return false;
            }
            // Such lost blocks take no block of the parse, so the blocks behind them meet their own parse (C2-B-137).
            $esCount = count($es);
            $asCount = count($as);
            while ($takes && $esCount - $at - 1 > $asCount - $read && $c->w->isLost($es[$at + 1])) {
                $at++;
            }
        }

        return true;
    }

    /**
     * Whether the inlines of `e` hold a lost soft break: its blank line split the block, which is reported there.
     */
    public static function holdsLostBreak(Check $c, Node $e): bool
    {
        if (!self::isInlineParent($e)) {
            return false;
        }
        foreach ($e->children() as $node) {
            if (($node->type === 'soft_break' && $c->w->isLost($node)) || self::holdsLostBreak($c, $node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares the blocks `es` of `owner`, as they were written, with their parse `as`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $as
     * @param bool $absorbed
     * @param \MarkupCarve\Carve\Writer\Check\Node $owner
     */
    public static function compareBlocks(Check $c, array $es, array $as, Node $owner, bool $absorbed = false): void
    {
        $before = $c->changes();
        if (!self::compareInOrder($c, $es, $as, $absorbed)) {
            return;
        }
        if (count($es) === count($as) || $c->changes() > $before) {
            return;
        }
        // What a lost block was written as has its own count of blocks.
        foreach ($es as $e) {
            if ($c->w->isLost($e) || self::holdsLostBreak($c, $e)) {
                return;
            }
        }
        if ($absorbed && count($es) < count($as)) {
            return;
        }
        // A line of a line block that reads as its closer ends the block (C2-B-123).
        $missing = $es[count($as)] ?? null;
        // The blocks below differ in number, so a line of one of them may have opened a block (C2-W-092).
        $c->misaligned = true;
        if ($owner->type === 'line_block' && $missing !== null) {
            self::escapeLineStart($c, $missing->children(), 0, $missing);
        } elseif ($missing !== null) {
            self::missingBlock($c, $missing);
        } else {
            $c->losses[] = [$owner, null];
        }
    }

    /**
     * carve-js `compareGroup` (write.ts): compares the group `$group` of `$document`, written as `$source` (a leading
     * line feed where the group is not first), with its parse.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     * @param string $source
     */

    /**
     * A table can start on a later line of a paragraph, so block-by-block comparison cannot always reach the text pipe
     * that closes its row: the last bare `|` of the paragraph takes the backslash (carve-js write.ts).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param \MarkupCarve\Carve\Writer\Check\Node $parsed
     */
    private static function escapeTableCloser(WriterState $w, Check $check, array $group, Node $parsed): bool
    {
        $paragraph = count($group) === 1 && $group[0]->type === 'paragraph' ? $group[0] : null;
        if ($paragraph === null) {
            return false;
        }
        $table = false;
        foreach ($parsed->children() as $node) {
            $table = $table || $node->type === 'table';
        }
        $piped = false;
        foreach ($paragraph->children() as $node) {
            $piped = $piped || ($node->value !== null && str_contains($node->value, "\n") && str_contains($node->value, '|'));
        }
        if (!$table || !$piped) {
            return false;
        }
        $pending = $paragraph->children();
        while (($node = array_pop($pending)) !== null) {
            if ($node->type === 'text') {
                $at = strrpos($node->value ?? '', '|');
                if ($at !== false && !in_array($at, $w->escapesOf($node), true)) {
                    $check->escapes[] = new Escape($node, $at);

                    return true;
                }
            }
            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * One round's comparison of the written group `group` of `document` with the parse of its source (carve-js write.ts).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     * @param string $source
     */
    public static function compareGroup(WriterState $w, Node $document, array $group, string $source, bool $first): Check
    {
        $check = new Check($w);
        $check->source = $source;
        $parsed = self::parseChunk($w, $source);
        $w->parsed = $parsed;
        if (self::escapeTableCloser($w, $check, $group, $parsed)) {
            return $check;
        }
        self::compareBlocks($check, $group, $parsed->children(), $document);
        self::escapeOpeners($check, $parsed, $first ? 1 : 2);

        return $check;
    }
}
