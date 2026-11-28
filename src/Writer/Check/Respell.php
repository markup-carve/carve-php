<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use Closure;
use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;

/**
 * The respelling of a node that did not read back (C2-W-088, C2-W-125), a port of carve-js write-check.ts:
 * `respell`, the 26 `RESPELL_STEPS` in their order, and the probes of a content on its own.
 */
final class Respell
{
    /**
     * The rounds of one probe: past it, the step runs again in the next round of the writer.
     *
     * @var int
     */
    private const MAX_PROBE_ROUNDS = 32;

    /**
     * The inlines behind a node that a probe across its closing mark reads, at most.
     *
     * @var int
     */
    private const MAX_ACROSS = 16;

    /**
     * The openers of a construct that can run on over a closing mark.
     *
     * @var string
     */
    private const ACROSS_OPENERS = '[`<{$:';

    /**
     * The nodes spelled with one mark before and one after their content, or with a brace around those marks.
     *
     * @var array<string, true>
     */
    private const PAIRED_MARKS = [
        'emphasis' => true,
        'strong' => true,
        'underline' => true,
        'strike' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
        'insert' => true,
        'delete' => true,
        'link' => true,
    ];

    /**
     * The step names of `RESPELL_STEPS`, in carve-js order. Steps that carve-js writes as an arrow keep a name here.
     *
     * @var list<string>
     */
    public const STEPS = [
        'braceAtContentEnd', 'escapeBefore', 'markAtContentEdgeForm', 'contentBraceBeforeStart', 'contentStart',
        'footnoteBrace', 'contentConstruct', 'escapeContentOpener', 'escapeAcrossCloser', 'escapeInside',
        'escapeCloserBeforeBrace', 'unclosedBrackets', 'escapeAfter', 'contentLine', 'escapeOwnerBrackets',
        'ownerMark', 'otherFormStep', 'escapeBefore', 'escapeInside', 'inContent', 'escapeLoneBracket',
        'escapeTextNote', 'keyCaret', 'inContentAlone', 'noteCaret', 'escapeAttributeKey',
    ];

    /**
     * The node `es[i]` did not read back. The ways to make it form are tried one per round (C2-W-088).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    public static function respell(Check $c, array $es, int $i): bool
    {
        $e = $es[$i];
        // A node that the writer reported as lost when it wrote it reads back as something else (C2-W-124).
        if ($c->w->isLost($e) || Compare::holdsLostBreak($c, $e)) {
            return false;
        }
        // Its escapes would only serve a node around it, and none can form (carve#6543).
        if (self::noneForms($c, $e)) {
            $c->losses[] = [$e, null];

            return false;
        }
        $id = spl_object_id($e);
        $attempt = $c->w->attempts[$id] ?? new Attempt(0, [], $e);
        $c->w->attempts[$id] = $attempt;
        $before = count($c->escapes);
        $stepsCount = count(self::STEPS);
        while ($attempt->step < $stepsCount) {
            $step = self::STEPS[$attempt->step++];
            if (!self::step($step, $c, $es, $i, $attempt)) {
                continue;
            }
            $escapesCount = count($c->escapes);
            for ($at = $before; $at < $escapesCount; $at++) {
                $escape = $c->escapes[$at];
                $attempt->escapes[] = $escape;
                if (self::isTrial($c, $step, $escape)) {
                    self::addTrial($c, $step, $escape);
                }
            }
            // A deferred escape can leave another opener for the next round.
            if ($step === 'escapeContentOpener') {
                $attempt->step--;
            }

            return true;
        }
        $c->losses[] = [$e, null];

        return false;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param string $step
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Attempt $attempt
     * @param int $i
     */
    private static function step(string $step, Check $c, array $es, int $i, Attempt $attempt): bool
    {
        $e = $es[$i];

        return match ($step) {
            'braceAtContentEnd' => self::braceAtContentEnd($c, $e),
            'escapeBefore' => self::escapeBefore($c, $es, $i),
            'markAtContentEdgeForm' => Compare::markAtContentEdge($c, $e) && self::otherForm($c, $es, $i, $attempt),
            'contentBraceBeforeStart' => self::contentBraceBeforeStart($c, $e),
            'contentStart' => self::escapeContentStart($c, $e),
            'footnoteBrace' => self::escapeFootnoteBrace($c, $e),
            'contentConstruct' => self::escapeContentConstruct($c, $e),
            'escapeContentOpener' => self::escapeContentOpener($c, $e),
            'escapeAcrossCloser' => self::escapeAcrossCloser($c, $es, $i),
            'escapeInside' => self::escapeInside($c, $es, $i),
            'escapeCloserBeforeBrace' => self::escapeCloserBeforeBrace($c, $es, $i),
            // C2-W-125: case five precedes case six, so a lone `[` of the content is escaped before the text behind.
            'unclosedBrackets' => !Compare::readsOtherBracketConstruct($c, $es, $i) && Compare::escapeUnclosedBrackets($c, $e),
            'escapeAfter' => self::escapeAfter($c, $es, $i),
            'contentLine' => Compare::escapeContentLine($c, $e),
            'escapeOwnerBrackets' => self::escapeOwnerBrackets($c, $es, $i),
            'ownerMark' => self::escapeOwnerMark($c, $e),
            'otherFormStep' => Compare::hasOtherForm($c, $e) && self::otherForm($c, $es, $i, $attempt),
            'inContent' => Compare::escapeInContent($c, $e),
            'escapeLoneBracket' => self::escapeLoneBracket($c, $es, $i),
            'escapeTextNote' => self::escapeTextNote($c, $es, $i),
            'keyCaret' => self::escapeKeyCaret($c, $e),
            'inContentAlone' => self::inContentAlone($c, $es, $i, $attempt),
            'noteCaret' => self::escapeNoteCaret($c, $e),
            'escapeAttributeKey' => self::escapeAttributeKey($c, $es, $i),
            default => false,
        };
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * Records the escape a step asked for as a trial, with what the round needs to know of it.
     */
    private static function addTrial(Check $c, string $step, Escape $escape): void
    {
        $c->trials[] = $escape;
        if ($step === 'contentStart') {
            Check::add($c->leadTrials, $escape);
        }
        if (self::stopsCodeSpan($step, $escape)) {
            Check::add($c->codeTrials, $escape);
        }
    }

    /**
     * The other spelling of `es[i]`, without the escapes the steps before asked for.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Attempt $attempt
     * @param int $i
     */
    private static function otherForm(Check $c, array $es, int $i, Attempt $attempt): bool
    {
        foreach ($attempt->escapes as $escape) {
            $c->retracted[] = $escape;
            Compare::mark($c, $escape->node, $escape->at, false);
        }
        $attempt->escapes = [];
        Compare::changeForm($c, $es[$i]);

        return true;
    }

    /**
     * Whether the escape a step asked for is a C2-W-125 trial, which the round tests on its own and which gives no kind.
     */
    private static function isTrial(Check $c, string $step, Escape $escape): bool
    {
        return $step !== 'escapeLoneBracket' && $step !== 'escapeOwnerBrackets' && !Check::has($c->reported, $escape);
    }

    /**
     * C2-W-088: a backtick that a probe of the content escapes can open a code span the tree does not hold.
     */
    private static function stopsCodeSpan(string $step, Escape $escape): bool
    {
        return in_array($step, ['escapeAcrossCloser', 'contentConstruct', 'escapeInside'], true)
            && (($escape->node->value ?? '')[$escape->at] ?? '') === '`';
    }

    private static function braceAtContentEnd(Check $c, Node $node): bool
    {
        // Case one leads only when this parse formed no node of this kind.
        foreach ($c->parsed as $candidate) {
            if ($candidate->type !== 'text') {
                return false;
            }
        }

        return Compare::escapeOpeningBrace($c, $node);
    }

    private static function contentBraceBeforeStart(Check $c, Node $node): bool
    {
        $mark = Compare::CLOSING_MARKS[$node->type] ?? null;
        $first = $node->children()[0] ?? null;

        // C2-W-125: case one precedes the content-start trial where both could form this node.
        return $mark !== null && $first?->type === 'text' && str_starts_with($first->value ?? '', $mark)
            && Compare::escapeOpeningBrace($c, $node);
    }

    /**
     * C2-W-125, case nine: the braced opener of `es[i]` reads as the key of a valid attribute block (C2-I-043).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeAttributeKey(Check $c, array $es, int $i): bool
    {
        $first = $es[$i]->children()[0] ?? null;
        if ($first?->type !== 'text') {
            return false;
        }
        $text = $c->w->spell->inlines($c->w, array_slice($es, $i), $c->flags);
        if (($text[0] ?? '') !== '{' || AttributeParser::read($text, 0, strlen($text), new Attrs()) < 0) {
            return false;
        }
        $value = $first->value ?? '';
        $equals = strspn($value, Tables::ATTR_KEY_REST);

        return ($value[$equals] ?? '') === '=' && Compare::escapeAt($c, $first, $equals);
    }

    /**
     * C2-W-125: the backslash of a closer in the content is taken back on its own, without the earlier trials.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Attempt $attempt
     * @param int $i
     */
    private static function inContentAlone(Check $c, array $es, int $i, Attempt $attempt): bool
    {
        if (count($attempt->escapes) < 2) {
            return false;
        }
        foreach ($attempt->escapes as $escape) {
            $c->retracted[] = $escape;
            Compare::mark($c, $escape->node, $escape->at, false);
        }
        $attempt->escapes = [];
        Compare::escapeInContent($c, $es[$i]);

        return true;
    }

    /**
     * C2-W-125, case five: the leading caret of a reference whose key is its content takes the backslash where the
     * bare caret keeps the link from forming.
     */
    private static function escapeKeyCaret(Check $c, Node $e): bool
    {
        if ($e->type !== 'link' || ($c->flags & Spell::IN_NOTE) !== 0 || !self::keyIsContent($e)) {
            return false;
        }
        $first = $e->children()[0] ?? null;

        return $first?->type === 'text' && str_starts_with($first->value ?? '', '^') && !Compare::isEscaped($c, $first, 0)
            && Compare::escapeAt($c, $first, 0);
    }

    /**
     * carve-js `keyIsContent` (write-inline.ts): a collapsed or shortcut reference whose key starts with a caret.
     */
    private static function keyIsContent(Node $link): bool
    {
        $form = $link->string('refForm');

        return ($form === 'collapsed' || $form === 'shortcut') && str_starts_with($link->string('key') ?? '', '^');
    }

    /**
     * C2-I-089, C2-W-125, case five: link or span text in a note is written with its leading caret bare; each is tried.
     */
    private static function escapeNoteCaret(Check $c, Node $e): bool
    {
        if ($e->type !== 'inline_footnote') {
            return false;
        }
        $asked = false;
        foreach ($e->children() as $child) {
            $first = $child->children()[0] ?? null;
            if (Compare::leadingCaret($child) && $first?->type === 'text' && !Compare::isEscaped($c, $first, 0)) {
                $asked = Compare::escapeAt($c, $first, 0) || $asked;
            }
        }

        return $asked;
    }

    /**
     * C2-I-089, C2-W-125: each `^[` of text before `es[i]` takes the backslash at its bracket (C2-W-090).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeTextNote(Check $c, array $es, int $i): bool
    {
        $asked = false;
        for ($j = 0; $j < $i; $j++) {
            $text = $es[$j];
            if ($text->type !== 'text') {
                continue;
            }
            $value = $text->value ?? '';
            for ($at = strpos($value, '^['); $at !== false; $at = strpos($value, '^[', $at + 1)) {
                if (!Compare::isEscaped($c, $text, $at) && !Compare::isEscaped($c, $text, $at + 1)) {
                    $asked = Compare::escapeAt($c, $text, $at + 1) || $asked;
                }
            }
        }

        return $asked;
    }

    /**
     * C2-W-125: a lone bracket in the content of an inline footnote or extension, where its closer was not asked for.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeLoneBracket(Check $c, array $es, int $i): bool
    {
        $e = $es[$i];
        if (($e->type !== 'inline_footnote' && $e->type !== 'inline_extension') || !$e->hasChildren()) {
            return false;
        }
        $kindless = !Compare::readsBracketConstruct($c, $e);
        $asked = false;
        // C2-W-088: where the parse holds no construct of its own there, the backslash is one of C2-W-125 and gives no kind.
        foreach (self::unclosedOpeners($c, $e) as [$text, $at]) {
            $asked = ($kindless ? Compare::escapeBracket($c, $text, $at) : Compare::escapeAt($c, $text, $at)) || $asked;
        }

        return $asked || Compare::escapeMark($c, $e->children(), 0, 0, '[');
    }

    public static function escapeUnclosedOpeners(Check $c, Node $e): bool
    {
        $asked = false;
        foreach (self::unclosedOpeners($c, $e) as [$text, $at]) {
            $asked = Compare::escapeAt($c, $text, $at) || $asked;
        }

        return $asked;
    }

    /**
     * Each bracket of the content of `e` that no later `]` of it closes.
     *
     * @return list<array{\MarkupCarve\Carve\Writer\Check\Node, int}>
     */
    private static function unclosedOpeners(Check $c, Node $e): array
    {
        $open = [];
        foreach (Compare::textsBelow($e) as $text) {
            $value = $text->value ?? '';
            for ($at = 0, $length = strlen($value); $at < $length; $at++) {
                $char = $value[$at];
                if (($char !== '[' && $char !== ']') || Compare::isEscaped($c, $text, $at)) {
                    continue;
                }
                if ($char === '[') {
                    $open[] = [$text, $at];
                } else {
                    array_pop($open);
                }
            }
        }

        return $open;
    }

    /**
     * C2-W-125: a closer, or a lone bracket, of the node around `e` stands in the content of `e` and ended that node
     * early. Its first one takes the backslash.
     */
    private static function escapeOwnerMark(Check $c, Node $e): bool
    {
        $owner = $c->owner;
        $mark = $owner === null ? null : (Compare::CLOSING_MARKS[$owner->type] ?? null);
        if ($owner === null || $mark === null || !$e->hasChildren()) {
            return false;
        }
        if ($mark === ']' && isset(Compare::BRACKETED[$owner->type])) {
            return false;
        }
        // C2-W-069, C2-W-093: a paired owner whose delimiter pairs in the content takes the braced form, as in `closedEarly`.
        if (Compare::isPaired($owner) && Compare::hasOtherForm($c, $owner)) {
            foreach (Compare::textsBelow($e) as $text) {
                if (self::holdsPairingMark($text->value ?? '', $mark)) {
                    Compare::changeForm($c, $owner);

                    return true;
                }
            }
        }

        return Compare::escapeCloser($c, $e->children(), 0, 0, $mark)
            || ($mark === ']' && Compare::escapeMark($c, $e->children(), 0, 0, '['));
    }

    /**
     * C2-W-125, case five: the bare `]` below `e` that close no bare `[` of the content of the bracketed node around
     * `e`, or else the bare `[` below `e` that no `]` of that content closes.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeOwnerBrackets(Check $c, array $es, int $i): bool
    {
        $owner = $c->owner;
        $e = $es[$i];
        if ($owner === null || !isset(Compare::BRACKETED[$owner->type]) || !$e->hasChildren()) {
            return false;
        }
        $below = [];
        foreach (Compare::textsBelow($e) as $text) {
            $below[spl_object_id($text)] = true;
        }
        $open = [];
        $inner = null;
        $bare = static fn (Node $node, int $offset): bool => !Compare::isEscaped($c, $node, $offset);
        $closers = Compare::contentClosers($owner->children(), $bare, $inner, $open);
        $asked = false;
        foreach ($closers as [$text, $at, $partner]) {
            if ($partner === null && isset($below[spl_object_id($text)])) {
                $asked = Compare::escapeBracket($c, $text, $at) || $asked;
            }
        }
        if (!$asked) {
            foreach ($open as [$text, $at]) {
                if (isset($below[spl_object_id($text)])) {
                    $asked = Compare::escapeBracket($c, $text, $at) || $asked;
                }
            }
        }

        return $asked;
    }

    /**
     * Asks for the escape that keeps the text before `es[i]` from reading on into its opening markup.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeBefore(Check $c, array $es, int $i): bool
    {
        $before = $i > 0 ? Compare::lastText($es[$i - 1]) : null;

        return Compare::escapeEnd($c, $before, true) || self::escapeNameSigil($c, $before, $es[$i]);
    }

    /**
     * C2-W-125, case three: the sigil `:name` glued before the bracket of `e` reads it as an extension.
     */
    private static function escapeNameSigil(Check $c, ?Node $text, Node $e): bool
    {
        if ($text === null || !str_starts_with($c->w->spell->inlines($c->w, [$e], $c->flags), '[')) {
            return false;
        }
        $value = $text->value ?? '';
        $start = strlen($value);
        while ($start > 0 && Smart::isExtensionNameChar($value[$start - 1])) {
            $start--;
        }
        // C2-I-094: the name starts with a letter or `_`.
        $valueLength = strlen($value);
        while ($start < $valueLength && ($value[$start] === '-' || ctype_digit($value[$start]))) {
            $start++;
        }

        return $start < strlen($value) && $start > 0 && $value[$start - 1] === ':' && Compare::escapeAt($c, $text, $start - 1);
    }

    /**
     * C2-W-099, C2-W-093: a content that starts with the mark of its own opener continues the run of that mark.
     */
    private static function escapeContentStart(Check $c, Node $e): bool
    {
        $mark = Compare::CLOSING_MARKS[$e->type] ?? null;
        $first = $e->children()[0] ?? null;

        return $mark !== null && $mark !== ']' && $first?->type === 'text' && str_starts_with($first->value ?? '', $mark)
            && Compare::escapeAt($c, $first, 0);
    }

    /**
     * An opener in superscript text mixed with other content can take the wrapper's closer.
     */
    private static function escapeContentOpener(Check $c, Node $e): bool
    {
        if ($e->type !== 'superscript' || !$e->hasChildren()) {
            return false;
        }
        $found = false;
        foreach ($e->children() as $child) {
            $value = $child->value ?? '';
            // A run made entirely of openers follows the ordinary repair and budget rule.
            if ($child->type !== 'text' || preg_match('/[^{^]/', $value) !== 1) {
                continue;
            }
            for ($at = strpos($value, '{^'); $at !== false; $at = strpos($value, '{^', $at + 1)) {
                $found = Compare::escapeAt($c, $child, $at) || $found;
            }
        }

        return $found;
    }

    private static function escapeFootnoteBrace(Check $c, Node $e): bool
    {
        if ($e->type !== 'inline_footnote') {
            return false;
        }
        $children = $e->children();
        $first = $children[0] ?? null;
        $last = $children === [] ? null : $children[count($children) - 1];
        if ($first?->type !== 'text' || $last?->type !== 'comment' || $last->field('form') !== 'line') {
            return false;
        }
        $paired = false;
        foreach ($children as $node) {
            $paired = $paired || $node->type === 'strong' || $node->type === 'emphasis';
        }
        if (!$paired || preg_match('/\{[*\/]/', $first->value ?? '', $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        return Compare::escapeAt($c, $first, $match[0][1]);
    }

    /**
     * Asks for the escape of the first character of the text behind `es[i]` (C2-I-104, C2-W-125).
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeAfter(Check $c, array $es, int $i): bool
    {
        $next = Compare::textAt($es, $i + 1);

        return $next !== null && Compare::escapeAt($c, $next, 0);
    }

    /**
     * Asks for the escape that keeps the end of the content of `es[i]` from reading on into its closing markup.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeInside(Check $c, array $es, int $i): bool
    {
        $e = $es[$i] ?? null;
        if ($e === null || !$e->hasChildren()) {
            return false;
        }

        return Compare::escapeEnd($c, Compare::lastText($e), true, self::closerOf($c->w, $e, $c->flags));
    }

    /**
     * C2-W-125, case five: a closer in content precedes a brace that follows the node.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeCloserBeforeBrace(Check $c, array $es, int $i): bool
    {
        $e = $es[$i] ?? null;
        $next = $es[$i + 1] ?? null;
        if ($e === null || $next?->type !== 'text' || !str_starts_with($next->value ?? '', '}')) {
            return false;
        }
        if (!str_contains(Compare::lastText($e)->value ?? '', '{')) {
            return false;
        }

        return Compare::escapeInContent($c, $e);
    }

    /**
     * The writer of a probe of the inlines `expected`: its own attempts, losses and escapes. Only the escapes of the
     * probed text nodes are read, so only those are copied.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     */
    private static function probeWriter(Check $c, array $expected): WriterState
    {
        $w = clone $c->w;
        $w->escapes = [];
        $w->escaped = [];
        foreach ($expected as $node) {
            foreach ($node->type === 'text' ? [$node] : Compare::textsBelow($node) as $text) {
                $own = $c->w->escapes[spl_object_id($text)] ?? null;
                if ($own !== null) {
                    $w->escapes[spl_object_id($text)] = $own;
                    $w->escaped[spl_object_id($text)] = $text;
                }
            }
        }
        $w->attempts = [];

        return $w;
    }

    /**
     * C2-W-088 on the inlines `expected` of `e` written and parsed on their own. Null where the inlines do not read as
     * one paragraph, which tells nothing; otherwise whether an escape was asked for. A probe is made once for each
     * state it reads (`probeKey`) and replayed after.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     * @param string $site
     * @param int $flags
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $takes
     */
    private static function probeEscapes(Check $c, Node $e, array $expected, int $flags, string $site, Closure $takes): ?bool
    {
        $key = self::probeKey($c, $e, $expected, $flags, $site);
        $known = $c->w->shared->probes[$key] ?? null;
        if ($known !== null) {
            foreach ($known['taken'] as [$node, $at]) {
                Compare::escapeAt($c, $node, $at);
            }
            foreach ($known['leads'] as [$node, $at]) {
                self::markLead($c, $node, $at);
            }

            return $known['found'];
        }
        $before = count($c->escapes);
        $found = self::runProbe($c, $e, $expected, $flags, $takes);
        $taken = array_slice($c->escapes, $before);
        // A probe cut short by the limit is not kept: its group is refused (C2-W-133).
        if (!$c->w->exhausted()) {
            $c->w->shared->probes[$key] = [
                'found' => $found,
                'taken' => array_map(static fn (Escape $escape): array => [$escape->node, $escape->at], $taken),
                'leads' => array_values(array_map(
                    static fn (Escape $escape): array => [$escape->node, $escape->at],
                    array_filter($taken, static fn (Escape $escape): bool => Check::has($c->leadTrials, $escape)),
                )),
            ];
        }

        return $found;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     * @param int $flags
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $takes
     */
    private static function runProbe(Check $c, Node $e, array $expected, int $flags, Closure $takes): ?bool
    {
        $w = self::probeWriter($c, $expected);
        try {
            return self::probeRounds($c, $w, $e, $expected, $flags, $takes);
        } finally {
            // carve-js spreads the writer into the probe, so the forms a probe write decides are the writer's own.
            $c->w->forms = $w->forms;
            $c->w->glued = $w->glued;
            $c->w->bracedAnyway = $w->bracedAnyway;
            $c->w->linkPlaced = $w->linkPlaced;
            $c->w->lossLog = $w->lossLog;
            $c->w->starts = $w->starts;
            $c->w->raws = $w->raws;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     * @param int $flags
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $takes
     */
    private static function probeRounds(Check $c, WriterState $w, Node $e, array $expected, int $flags, Closure $takes): ?bool
    {
        $found = false;
        for ($round = 0; $round < self::MAX_PROBE_ROUNDS; $round++) {
            // C2-W-133: past the repair limit the group is refused after this round, so nothing more is probed.
            if ($c->w->exhausted()) {
                return $found;
            }
            $taken = self::probeRound($c, $w, $e, $expected, $flags, $takes);
            // A first parse that is no paragraph, or that differs where no escape helps, tells nothing.
            if ($taken === null) {
                return $round === 0 ? null : $found;
            }
            if ($taken === []) {
                return $found;
            }
            $found = true;
            foreach ($taken as $escape) {
                $id = spl_object_id($escape->node);
                $w->escapes[$id][] = $escape->at;
                $w->escaped[$id] = $escape->node;
            }
            foreach ($taken as $escape) {
                $id = spl_object_id($escape->node);
                sort($w->escapes[$id]);
            }
        }

        return $found;
    }

    /**
     * One round of a probe: the escapes it takes over into `c`, or null where its parse tells nothing.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     * @param int $flags
     * @param \Closure(\MarkupCarve\Carve\Writer\Check\Node, int): bool $takes
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>|null
     */
    private static function probeRound(Check $c, WriterState $w, Node $e, array $expected, int $flags, Closure $takes): ?array
    {
        $source = $w->spell->inlines($w, $expected, $flags) . "\n";
        $parsed = Compare::parseChunk($c->w, $source)->children();
        $block = $parsed[0] ?? null;
        if (count($parsed) !== 1 || $block?->type !== 'paragraph') {
            return null;
        }
        $probe = new Check($w);
        $formsAround = $c->formsAround || !self::unformable($e, $c->flags);
        foreach ($c->around as [$list, $at, $around]) {
            $formsAround = $formsAround || !self::unformable($list[$at], $around);
        }
        $probe->formsAround = $formsAround;
        $probe->flags = $flags;
        $probe->source = $source;
        if ($e->type === 'inline_footnote' && !Compare::readsBracketConstruct($c, $e)) {
            $probe->note = $e;
        }
        Compare::compareInlines($probe, $expected, $block->children(), $e);
        // C2-W-125, case five: the lone brackets of such a note keep it open in its context.
        if ($probe->note !== null) {
            $before = count($probe->escapes);
            self::escapeUnclosedOpeners($probe, $probe->note);
            foreach (array_slice($probe->escapes, $before) as $escape) {
                Check::add($probe->closers, $escape);
            }
        }
        // C2-W-088: a probe round escapes every opener of each kind its parse reports (kind rounds, write-kinds.ts).
        // A closer that ended a node early is no reported construct: its backslash is C2-W-125's and gives no kind.
        if ($w->kindRounds) {
            $leaf = [Node::of('paragraph', $expected)];
            Kinds::escapeKinds($probe, $leaf, $probe->closers + $probe->readOn);
            Kinds::escapePartnerClosers($probe, $leaf);
        }
        Compare::extendChains($probe);
        $taken = [];
        foreach ($probe->escapes as $escape) {
            if ($takes($escape->node, $escape->at) && Compare::escapeAt($c, $escape->node, $escape->at)) {
                $taken[] = $c->last();
                if (Check::has($probe->leadTrials, $escape)) {
                    self::markLead($c, $escape->node, $escape->at);
                }
            }
        }

        return $taken === [] && $probe->losses !== [] ? null : $taken;
    }

    /**
     * Marks the escape `[node, at]` that `c` holds as a content-start trial, which the round tests in its context.
     */
    private static function markLead(Check $c, Node $node, int $at): void
    {
        foreach ($c->escapes as $escape) {
            if ($escape->node === $node && $escape->at === $at) {
                Check::add($c->leadTrials, $escape);

                return;
            }
        }
    }

    /**
     * The id of `node` for the keys of the probes: one per node object.
     */
    private static function nodeIdOf(WriterState $w, Node $node): int
    {
        $key = spl_object_id($node);
        if (!isset($w->shared->nodeIds[$key])) {
            // The map keys by object id, so the node stays alive while the write runs, as a key of a carve-js Map does.
            $w->shared->held[] = $node;
            $w->shared->nodeIds[$key] = count($w->shared->nodeIds);
        }

        return $w->shared->nodeIds[$key];
    }

    /**
     * The text `value` that stands in a probe for markup beside `anchor`. A probe made again must see the same node.
     */
    private static function standIn(WriterState $w, Node $anchor, string $role, string $value): Node
    {
        $name = "{$role} {$value}";

        $id = spl_object_id($anchor);
        if (!isset($w->shared->standIns[$id])) {
            $w->shared->held[] = $anchor;
        }

        return $w->shared->standIns[$id][$name] ??= Node::text($value);
    }

    /**
     * The state a probe reads, as a key.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param \MarkupCarve\Carve\Writer\Check\Node $e
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $expected
     * @param string $site
     * @param int $flags
     */
    private static function probeKey(Check $c, Node $e, array $expected, int $flags, string $site): string
    {
        $w = $c->w;
        $parts = [$site, $flags, $w->kindRounds, self::nodeIdOf($w, $e), self::nodeState($c, $e)];
        foreach ($expected as $node) {
            $parts[] = self::nodeIdOf($w, $node);
        }
        $pending = $expected;
        while (($node = array_pop($pending)) !== null) {
            array_push($pending, ...$node->children());
            $state = self::nodeState($c, $node);
            if ($state !== null) {
                $parts[] = self::nodeIdOf($w, $node);
                $parts[] = $state;
            }
        }

        return (string)json_encode($parts);
    }

    /**
     * What the writer and the comparison `c` hold for `node`, or null where it holds nothing.
     *
     * @return list<mixed>|null
     */
    private static function nodeState(Check $c, Node $node): ?array
    {
        $w = $c->w;
        $id = spl_object_id($node);
        $escapes = $node->type === 'text' ? ($w->escapes[$id] ?? null) : null;
        $marks = $node->type === 'text' ? ($c->marks[$id] ?? null) : null;
        $form = $w->forms[$id] ?? null;
        $keys = $w->lostKeys[$id] ?? null;
        $lost = isset($w->lost[$id]);
        if ($escapes === null && $marks === null && $form === null && $keys === null && !$lost) {
            return null;
        }

        return [$escapes, $marks, $form, $keys, $lost];
    }

    /**
     * C2-W-088: the content of `e`, written and parsed on its own, holds a construct that the tree does not. A content
     * that does not read as one paragraph tells nothing.
     */
    private static function escapeContentConstruct(Check $c, Node $e): bool
    {
        $children = $e->children();
        if ($children === []) {
            return false;
        }
        $word = self::standIn($c->w, $e, 'word', 'x');
        $flat = array_map(
            static fn (Node $node): Node => $node->type === 'soft_break' ? self::standIn($c->w, $node, 'space', ' ') : $node,
            $children,
        );
        // A content that starts with a line break reads as a paragraph only behind the word.
        $variants = $children[0]->isBreak()
            ? [[$word, ...$children], [$word, ...$flat]]
            : [$children, [$word, ...$children], [$word, ...$flat]];
        foreach ($variants as $expected) {
            $found = self::probeEscapes($c, $e, $expected, Compare::contentFlags($c, $e), 'content', static fn (): bool => true);
            if ($found !== null) {
                return $found;
            }
        }

        return false;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-W-088: a construct that opens in the content of the paired node `es[i]` and runs on over its closing mark
     * into the inlines behind it keeps the node from forming. The content, the closing mark as text and the inlines
     * behind it are probed together.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $i
     */
    private static function escapeAcrossCloser(Check $c, array $es, int $i): bool
    {
        $e = $es[$i];
        $flags = Compare::contentFlags($c, $e);
        $suffix = self::mayCross($c, $e, $flags) ? self::closerOf($c->w, $e, $c->flags) : null;
        if ($suffix === null) {
            return false;
        }
        $end = $i + 1;
        // A label or a bracket pair can run on over a line break (C2-I-076).
        $esCount = count($es);
        while ($end < $esCount && $end - $i <= self::MAX_ACROSS) {
            $end++;
        }
        $outer = $end === count($es) ? self::outerInlines($c, $end - $i) : [];
        $closer = self::standIn($c->w, $e, 'closer', $suffix);
        $word = self::standIn($c->w, $e, 'word', 'x');
        $behind = [];
        foreach ($outer as $node) {
            foreach ($node->type === 'text' ? [$node] : Compare::textsBelow($node) as $text) {
                $behind[spl_object_id($text)] = true;
            }
        }
        $inlines = [...$e->children(), $closer, ...array_slice($es, $i + 1, $end - $i - 1), ...$outer];
        // The stand-ins take no escape, and neither does a mark of the node in its content (C2-W-069).
        $mark = Compare::CLOSING_MARKS[$e->type] ?? null;
        $own = [];
        foreach (Compare::textsBelow($e) as $text) {
            $own[spl_object_id($text)] = true;
        }
        $marks = $outer !== [] ? self::aroundMarks($c, $mark) : '';
        $takes = static function (Node $node, int $at) use ($closer, $word, $behind, $own, $mark, $marks): bool {
            $char = ($node->value ?? '')[$at] ?? '';

            return $node !== $closer && $node !== $word && !isset($behind[spl_object_id($node)])
                && !(isset($own[spl_object_id($node)]) && $char === $mark) && ($char === '' || !str_contains($marks, $char));
        };
        $before = count($c->escapes);
        // A note's content is read behind its sigil, which the probe stands in for with a mark that opens nothing.
        $sigil = $e->type === 'inline_footnote' ? self::standIn($c->w, $e, 'sigil', ';') : null;
        $found = $sigil !== null
            ? self::probeEscapes(
                $c,
                $e,
                [$sigil, ...$inlines],
                $flags,
                'across',
                static fn (Node $node, int $at): bool => $node !== $sigil && $takes($node, $at),
            ) === true
            : self::probeEscapes($c, $e, $inlines, $flags, 'across', $takes) === true
                || self::probeEscapes($c, $e, [$word, ...$inlines], $flags, 'across', $takes) === true;
        if ($found && $outer !== []) {
            self::reportOpeners($c, $before);
        }

        return $found;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * A span whose attribute block holds a line break forms in no spelling (C2-I-040, C2-I-028).
     */
    private static function unformable(Node $node, int $flags): bool
    {
        if ($node->type !== 'span' || ($flags & Spell::IN_CELL) !== 0) {
            return false;
        }

        return self::holdsBreak($node->field('attrs'));
    }

    /**
     * Whether a key or value of the attributes `attrs` holds a line break, which its brace block then holds.
     */
    private static function holdsBreak(mixed $attrs): bool
    {
        if (is_string($attrs)) {
            return strpbrk($attrs, "\r\n") !== false;
        }
        if (!is_array($attrs)) {
            return false;
        }
        foreach ($attrs as $key => $value) {
            if (self::holdsBreak((string)$key) || self::holdsBreak($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Neither `e` nor a node around it can form, so no escape makes one form.
     */
    private static function noneForms(Check $c, Node $e): bool
    {
        if (!self::unformable($e, $c->flags) || $c->formsAround) {
            return false;
        }
        foreach ($c->around as [$list, $at, $flags]) {
            if (!self::unformable($list[$at], $flags)) {
                return false;
            }
        }

        return true;
    }

    private static function isPairedMark(Node $node): bool
    {
        return isset(self::PAIRED_MARKS[$node->type]) && $node->hasChildren();
    }

    /**
     * Whether a construct can open in the content of `e` and run on over its closer.
     */
    private static function mayCross(Check $c, Node $e, int $flags): bool
    {
        if ((!self::isPairedMark($e) && $e->type !== 'span' && $e->type !== 'inline_footnote') || !$e->hasChildren()) {
            return false;
        }
        $opens = false;
        foreach (Compare::textsBelow($e) as $text) {
            $opens = $opens || self::opensAcross($text->value ?? '');
        }
        if (!$opens) {
            return false;
        }

        return $e->type !== 'link' || self::canCrossLinkCloser($c->w, $e->children(), $flags);
    }

    /**
     * The opener of a construct that runs on over the closers around its node is reported; any other is a trial.
     */
    private static function reportOpeners(Check $c, int $from): void
    {
        foreach (array_slice($c->escapes, $from) as $escape) {
            $char = ($escape->node->value ?? '')[$escape->at] ?? '';
            if ($char !== '' && str_contains(self::ACROSS_OPENERS, $char)) {
                Check::add($c->reported, $escape);
            }
        }
    }

    /**
     * At the end of its list, the construct runs on over the closers of the nodes around it and the inlines behind
     * them, up to `MAX_ACROSS` nodes in all.
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    private static function outerInlines(Check $c, int $taken): array
    {
        $inlines = [];
        for ($k = count($c->around) - 1; $k >= 0 && $taken <= self::MAX_ACROSS; $k--) {
            [$list, $at, $flags] = $c->around[$k];
            $owner = $list[$at];
            $suffix = self::closerOf($c->w, $owner, $flags);
            if ($suffix === null) {
                break;
            }
            $inlines[] = self::standIn($c->w, $owner, 'closer', self::closingStandIn($owner, $suffix));
            $end = $at + 1;
            $listCount = count($list);
            while ($end < $listCount && $taken <= self::MAX_ACROSS) {
                $inlines[] = $list[$end++];
                $taken++;
            }
            if ($end < count($list)) {
                break;
            }
        }

        return $inlines;
    }

    /**
     * The mark `mark` and those of the paired nodes around the content under comparison.
     */
    private static function aroundMarks(Check $c, ?string $mark): string
    {
        $marks = $mark ?? '';
        foreach ($c->around as [$list, $at]) {
            $owner = $list[$at];
            if (self::isPairedMark($owner) && $owner->type !== 'link') {
                $marks .= Compare::CLOSING_MARKS[$owner->type] ?? '';
            }
        }

        return $marks;
    }

    /**
     * A closing mark of an outer node pairs with nothing in the probe, so it stands in as characters that open nothing.
     */
    private static function closingStandIn(Node $node, string $suffix): string
    {
        return isset(Compare::BRACKETED[$node->type]) || $node->type === 'link' ? $suffix : str_repeat(';', strlen($suffix));
    }

    /**
     * The markup that closes a paired or bracketed node, as it is written: its spelling behind its content, or null.
     */
    private static function closerOf(WriterState $w, Node $node, int $flags): ?string
    {
        $type = $node->type;
        if ((!isset(self::PAIRED_MARKS[$type]) && !isset(Compare::BRACKETED[$type])) || !$node->hasChildren()) {
            return null;
        }
        $spelled = $w->spell->inlines($w, [$node], $flags);
        $content = $w->spell->inlines($w, $node->children(), ($flags & ~Spell::AT_PAIRED_END) | Compare::contentFlag($type));
        if ($type === 'link') {
            return substr($spelled, 1 + strlen($content));
        }
        if (isset(self::PAIRED_MARKS[$type])) {
            return substr($spelled, strlen($spelled) - intdiv(strlen($spelled) - strlen($content), 2));
        }
        $start = $type === 'inline_footnote' ? 2 : (strpos($spelled, '[') === false ? 0 : (int)strpos($spelled, '[') + 1);

        return $start > 0 && substr($spelled, $start, strlen($content)) === $content ? substr($spelled, $start + strlen($content)) : null;
    }

    /**
     * The terminal link of a nested chain has no markup that can expose another label opener.
     */
    private static function settledTerminal(Node $link): bool
    {
        $children = $link->children();
        foreach ($children as $child) {
            if ($child->type === 'link') {
                return true;
            }
        }

        return count($children) === 1 && $children[0]->type === 'text';
    }

    /**
     * Whether bare text contains another construct opener or an unclosed bracket.
     */
    private static function textCanCross(WriterState $w, Node $node, int $flags, int &$brackets): bool
    {
        $escaped = array_flip($w->escapesOf($node));
        $value = $node->value ?? '';
        for ($at = 0, $length = strlen($value); $at < $length; $at++) {
            if (isset($escaped[$at]) || (($flags & Spell::IN_CELL) !== 0 && $value[$at] === '|')) {
                continue;
            }
            $char = $value[$at];
            if (str_contains('`<{$:', $char)) {
                return true;
            }
            if ($char === '[') {
                $brackets++;
            } elseif ($char === ']' && $brackets > 0) {
                $brackets--;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $children
     * @param int $flags
     */
    private static function canCrossLinkCloser(WriterState $w, array $children, int $flags): bool
    {
        $brackets = 0;
        $pending = array_reverse(array_map(static fn (Node $node): array => [$node, $flags], $children));
        while (($item = array_pop($pending)) !== null) {
            [$node, $itemFlags] = $item;
            if ($node->type === 'link') {
                if (!self::settledTerminal($node) || self::canCrossLinkCloser($w, $node->children(), $itemFlags | Spell::IN_BRACKET)) {
                    return true;
                }

                continue;
            }
            if ($node->type === 'text') {
                if (self::textCanCross($w, $node, $itemFlags, $brackets)) {
                    return true;
                }

                continue;
            }
            if ($node->type !== 'strong' && $node->type !== 'emphasis') {
                return true;
            }
            $inside = $itemFlags | ($node->type === 'strong' ? Spell::IN_STRONG : Spell::IN_EMPHASIS);
            $kids = $node->children();
            for ($at = count($kids) - 1; $at >= 0; $at--) {
                $pending[] = [$kids[$at], $inside];
            }
        }

        return $brackets !== 0;
    }

    private static function opensAcross(string $value): bool
    {
        foreach (str_split(self::ACROSS_OPENERS) as $opener) {
            if (str_contains($value, $opener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-047, C2-I-048: whether `value` holds `mark` where its neighbors let it open or close.
     */
    private static function holdsPairingMark(string $value, string $mark): bool
    {
        for ($at = strpos($value, $mark); $at !== false; $at = strpos($value, $mark, $at + 1)) {
            if ($at === 0 || $at === strlen($value) - 1) {
                return true;
            }
            $before = Chars::before($value, $at);
            $after = Chars::at($value, $at + 1);
            if ((!Chars::isWord($before) && !Chars::isSpace($after)) || (!Chars::isSpace($before) && !Chars::isWord($after))) {
                return true;
            }
        }

        return false;
    }
}
