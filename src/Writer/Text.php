<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\IncludeShape;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\Markers;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Inline\Scanner;
use MarkupCarve\Carve\Lint\DataModel;
use MarkupCarve\Carve\Lint\NodeRulesA;
use MarkupCarve\Carve\Node;

/**
 * The escapes of a text node (C2-W-088 to C2-W-099), decided from the contexts of `data/escape-contexts.json` before the
 * text is written: each character that a context can make an opener at its position takes a backslash, from the
 * characters around it in the written output, and from nothing that a later parse finds.
 */
final class Text
{
    /**
     * @var string
     */
    private const PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * The marks of braced openers and closers (C2-I-055).
     *
     * @var string
     */
    private const MARKS = '/*_~=+-^,#%';

    /**
     * @var array<string, true>
     */
    private const DEFINITIONS = ['link_reference_definition' => true, 'footnote' => true, 'abbreviation_def' => true];

    /**
     * Inline nodes whose spelling holds no bracket that an outer bracket pairs with: the reader takes an atom whole, and
     * a bracket construct closes its own brackets. Any other node holds no such bracket when no string in it holds `]`.
     *
     * @var array<string, true>
     */
    private const SEALED = [
        'comment' => true,
        'code' => true,
        'raw_inline' => true,
        'math' => true,
        'autolink' => true,
        'xref' => true,
        'footnote_ref' => true,
        'link' => true,
        'image' => true,
        'inline_footnote' => true,
    ];

    /**
     * C2-I-036: the inline nodes that take no attribute block glued behind them.
     *
     * @var array<string, true>
     */
    private const NO_HOSTS = [
        'mention' => true,
        'tag' => true,
        'smart_punctuation' => true,
        'abbreviation' => true,
        'comment' => true,
        'critic_comment' => true,
    ];

    /**
     * C2-I-036: the inline nodes that take an attribute block glued behind them.
     *
     * @var array<string, true>
     */
    private const HOSTS = [
        'span' => true,
        'link' => true,
        'image' => true,
        'autolink' => true,
        'code' => true,
        'literal_inline' => true,
        'math' => true,
        'footnote_ref' => true,
        'inline_footnote' => true,
        'symbol' => true,
        'inline_extension' => true,
        'emphasis' => true,
        'strong' => true,
        'delete' => true,
        'insert' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
    ];

    /**
     * The inline nodes whose spelling holds no delimiter that pairs with text around them.
     *
     * @var array<string, true>
     */
    private const WHOLE = [
        'code' => true,
        'raw_inline' => true,
        'math' => true,
        'literal_inline' => true,
        'comment' => true,
        'symbol' => true,
        'tag' => true,
        'mention' => true,
        'non_breaking_space' => true,
        'smart_punctuation' => true,
        'footnote_ref' => true,
        'autolink' => true,
    ];

    /**
     * C2-W-092: the punctuation a block opener of a first paragraph line starts with.
     *
     * @var string
     */
    private const LINE_OPENERS = '#=>^|+:%-*.[{`~$!<';

    /**
     * The characters a smart punctuation run goes on with (C2-I-104 to C2-I-117).
     *
     * @var string
     */
    private const RUN_CHARACTERS = '!+-.<=>()';

    /**
     * C2-I-092: the characters of a name and the dots that join its runs.
     *
     * @var string
     */
    private const NAME = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';

    /**
     * Characters that take a backslash wherever they stand in text: a backtick run and the two quotes smart typography
     * replaces (C2-W-095).
     *
     * @var string
     */
    private const ALWAYS = '`"\'';

    /**
     * The inline nodes written between backticks.
     *
     * @var array<string>
     */
    private const TICK_ATOMS = ['code', 'raw_inline', 'math', 'literal_inline'];

    /**
     * C2-W-088: the nodes whose content a leaf's backtick plan reads, written at the path of their `children`; a code
     * span reads over a bracket, so link and span text belong to the leaf around them.
     *
     * @var array<string, true>
     */
    private const PLAN_SPANS = [
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
        'span' => true,
    ];

    /**
     * The characters that start a symbol name or an inline extension name.
     */
    private const NAME_STARTS = Tables::SIGIL_SYMBOL_FIRST . Tables::SIGIL_INLINE_EXTENSION_FIRST;

    /**
     * Every first character of an escape trigger this class decides; WriterTablesTest holds it to the data file.
     *
     * @var string
     */
    /**
     * C2-W-146: the pair rules of lint, each with its mark.
     *
     * @var array<array{0: string, 1: string}>
     */
    private const PAIR_RULES = [
        ['_', 'deprecated-bare-underline'], ['~', 'deprecated-bare-strike'], ['=', 'deprecated-bare-highlight'],
        ['~~', 'markdown-strikethrough-double-tilde'], ['**', 'markdown-strong-double-star'],
    ];

    /**
     * @var string
     */
    public const DECIDED = '!"#$%\'(*+-./:<=>@[\\]^`{|}';

    /**
     * While set, the offsets each text path's last escape placed a backslash before, by path.
     *
     * @var array<string, array<int, true>>|null
     */
    public static ?array $recorded = null;

    /**
     * While `$recorded` is set, the escapes of each text path that are not the glued brace of C2-W-125 case one, by
     * path.
     *
     * @var array<string, array<int, true>>
     */
    public static array $recordedDecided = [];

    /**
     * How many backtick rounds escaped every potential opener of the kind; a leaf reads it to know its form took them.
     */
    public static int $tickKindRounds = 0;

    /**
     * C2-W-088: set where the bracket plan left a bracket bare that a node beside it may still pair, so the writer
     * reads the form back instead of trusting the plan.
     */
    public static bool $undecided = false;

    /**
     * Starts the record of a top-level block group; returns the record of the groups before it.
     */
    public static function enterGroup(): bool
    {
        $outer = self::$undecided;
        self::$undecided = false;

        return $outer;
    }

    /**
     * Ends the record of a top-level block group: whether it left an escape undecided. The record of all groups
     * goes on.
     */
    public static function leaveGroup(bool $outer): bool
    {
        $group = self::$undecided;
        self::$undecided = $outer || $group;

        return $group;
    }

    /**
     * While set, the bracket plan takes the backslash wherever it cannot decide, the form the writer falls back to
     * where the read-back rounds stop.
     */
    public static bool $conservative = false;

    /**
     * Whether the first write is of a supplied tree, which C2-W-124 reads back only where an escape is left undecided.
     */
    public static bool $suppliedTree = false;

    /**
     * Whether a conservative write leaves a sigil glued to a node bare, the first retry of an undecided write.
     */
    public static bool $bareSigils = false;

    /**
     * The bracket plan of the last text inside another node, by its value.
     *
     * @var array{string, array<string, true>, array<int, bool>}|null
     */
    private static ?array $nestedBrackets = null;

    /**
     * The recent answers of `opensBehind()`, by the two lines.
     *
     * @var array<string, bool>
     */
    private static array $behindReads = [];

    /**
     * Whether the last leaf around a nested text is near the depth cap, by its flattened text.
     *
     * @var array{string, bool}|null
     */
    private static ?array $nestedCap = null;

    /**
     * @var array{string, array{int, string}}|null
     */
    private static ?array $lastBlockRead = null;

    public static function isPunct(string $c): bool
    {
        return $c !== '' && strlen($c) === 1 && str_contains(self::PUNCT, $c);
    }

    /**
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c Context at the start of the text; the text is followed by `$next`.
     * @param int $next First character written after the text, 10 at a line end, -1 at the end of the content.
     *
     * @return string
     */
    public static function escape(string $value, Context $c, int $next): string
    {
        if ($c->writerState?->repairTrace !== null) {
            return $c->writerState->repairTrace->bareText($value, $c->textPath, $next);
        }

        return self::escapeText($value, $c, $next);
    }

    private static function escapeText(string $value, Context $c, int $next): string
    {
        $out = self::escapedText($value, $c, $next);
        if (self::$recorded !== null) {
            // A path without escapes is left out: its seed is empty, and a long paragraph has one per text node
            // (carve-php#5722).
            $escaped = array_filter($c->escapedAt);
            unset(self::$recordedDecided[$c->textPath]);
            if ($escaped === []) {
                unset(self::$recorded[$c->textPath]);
            } else {
                self::$recorded[$c->textPath] = $escaped;
                $decided = array_diff_key($escaped, $c->gluedBraces);
                if ($decided !== []) {
                    self::$recordedDecided[$c->textPath] = $decided;
                }
            }
        }

        return $out;
    }

    private static function escapedText(string $value, Context $c, int $next): string
    {
        $n = strlen($value);
        $c->escapeKinds = [];
        if ($n === 0) {
            return '';
        }
        if ((!$c->lineStart || ctype_alpha($value[0])) && strpbrk($value, self::PUNCT) === false) {
            // The escapes of the text before must not stand for this one in the record (C2-W-091).
            $c->escapedAt = [];

            return $value;
        }
        $marks = self::lineStartMark($value, $c, $next);
        $c->escapedAt = [];
        $c->gluedBraces = [];
        $out = '';
        $prev = $c->last;
        [$balanced, $ticks, $lineOpener] = self::loopFacts($value, $marks, $c, $next);
        $liveTicks = [];
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            $byte = ord($ch);
            if ($byte >= 0x80) {
                $len = Chars::len($value, $i);
                $out .= substr($value, $i, $len);
                $prev = Chars::at($value, $i);
                $i += $len - 1;

                continue;
            }
            $after = $i + 1 < $n ? Chars::at($value, $i + 1) : $next;
            if ($byte === 0x3A) {
                $last = self::innerColons($value, $i, $prev, [$marks, $lineOpener], $c->pairEscapes);
                if ($last >= $i) {
                    $c->escapedAt += array_fill($i, $last - $i + 1, false);
                    $out .= str_repeat(':', $last - $i + 1);
                    $i = $last;

                    continue;
                }
            }
            $own = ($i === $c->escapeFollowingPipeAt && $ch === '|') || isset($c->pairEscapes[$i]) || isset($c->behindMarker[$i])
                || isset($liveTicks[$i]);
            $c->gluedBrace = false;
            $repair = !$own && (($i === 0 && self::atomRunGoesOn($value, $c, $next)) || $i === $marks
                || (str_contains(self::PUNCT, $ch)
                && !($ch === '`' && $ticks !== null && !isset($ticks[$i]))
                && !($c->inBracket && isset($balanced[$i])) && self::needs($value, $i, $prev, $after, $c, $next)));
            $escape = $own || $repair;
            // C2-W-094: a bracket in the text of a bracketed node pairs with nothing outside it, so its escape is no
            // kind for the leaf.
            // C2-W-088: nor is the escape of a mark that would close its node early (C2-W-125, case five).
            if (
                $repair && !str_contains(self::ALWAYS, $ch) && !($ch === '[' && $c->inBracket && $i !== $marks)
                && !self::closesOpenMark($ch, $after, $c) && !self::gluedBrace($c)
            ) {
                $c->escapeKinds[$i] = self::kindOf($value, $i, $i === $marks, $after);
            } elseif (!$escape && $c->kinds !== [] && str_contains(self::PUNCT, $ch)) {
                $escape = self::potential($value, $i, $prev, $after, $c, $lineOpener);
            }
            // A brace that starts a line is left to the line rules, where a block attribute line may form.
            if (!$escape && $byte === 0x7B) {
                $end = self::literalBlockEnd($value, $i);
                if ($end > $i && !self::pairEscapeIn($c->pairEscapes, $i, $end)) {
                    $c->escapedAt += array_fill($i, $end - $i, false);
                    $out .= substr($value, $i, $end - $i);
                    $prev = 0x7D;
                    $i = $end - 1;

                    continue;
                }
            }
            if ($escape && $byte === 0x7B) {
                $liveTicks += self::liveTicks($value, $i, $c);
            }
            if ($escape && self::gluedBrace($c)) {
                $c->gluedBraces[$i] = true;
            }
            $c->escapedAt[$i] = $escape;
            $out .= $escape ? '\\' . $ch : $ch;
            $prev = $byte;
        }

        return $out;
    }

    /**
     * C2-W-146: whether a mark between `$from` and `$to` takes a backslash for a pair rule of lint, which an attribute
     * block copied whole would leave bare.
     *
     * @param array<int, bool> $pairEscapes
     * @param int $from
     * @param int $to
     */
    private static function pairEscapeIn(array $pairEscapes, int $from, int $to): bool
    {
        if ($pairEscapes === []) {
            return false;
        }
        // The range is the block `literalBlockEnd()` has just read, so the scan stays within its cost.
        for ($at = $from + 1; $at < $to; $at++) {
            if (isset($pairEscapes[$at])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `needs()` escaped the last character for C2-W-125 case one, a brace glued before a node.
     */
    private static function gluedBrace(Context $c): bool
    {
        return $c->gluedBrace;
    }

    /**
     * C2-I-005, C2-I-035: a valid attribute block of text behind no host is literal text, read whole, so nothing in it
     * takes a backslash. The end of the block that the bare brace at `$i` opens on its line, or -1.
     *
     * @param string $value
     * @param int $i
     * @param bool $empty Whether an empty block counts.
     *
     * @return int
     */
    private static function literalBlockEnd(string $value, int $i, bool $empty = false): int
    {
        $close = strpos($value, '}', $i + 1);
        if ($close === false) {
            return -1;
        }
        $line = strpos($value, "\n", $i);
        $end = AttributeParser::read($value, $i, $line === false ? strlen($value) : $line, new Attrs());

        return $end > 0 && ($empty || !AttributeParser::$lastEmpty) ? $end : -1;
    }

    /**
     * C2-I-093, C2-I-094: a colon between two colons opens nothing and is no potential opener of its kind. The last
     * offset of the colons from `$i` on that stand so, before the first offset a mark, a line opener or a pair escape
     * takes; below `$i` when there is none.
     *
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param array<int> $taken
     * @param array<int, mixed> $pairEscapes
     *
     * @return int
     */
    private static function innerColons(string $value, int $i, int $prev, array $taken, array $pairEscapes): int
    {
        // At the start of the text the colon before it belongs to another node (C2-W-095).
        if ($i === 0 || $prev !== 0x3A) {
            return -1;
        }
        $last = $i + strspn($value, ':', $i) - 2;
        foreach ($taken as $at) {
            if ($at >= $i && $at <= $last) {
                $last = $at - 1;
            }
        }
        for ($at = $i; $at <= $last; $at++) {
            if (isset($pairEscapes[$at])) {
                return $at - 1;
            }
        }

        return $last;
    }

    /**
     * What the escape loop reads of the context, which the loop does not change: the balanced brackets of the text, the
     * backticks that take a backslash, and the line opener a reported line kind escapes. Sets the local pair partners.
     *
     * @param string $value
     * @param int $marks
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return array{array<int, mixed>, array<int, true>|null, int}
     */
    private static function loopFacts(string $value, int $marks, Context $c, int $next): array
    {
        $c->localPartners = $c->here < 0 ? self::siblingPartners($value, $c) : null;

        return [
            $c->inBracket ? ($c->bracketPairs[$c->textPath] ?? []) : [],
            !$c->cellWrapped ? self::tickEscapes($value, $c, $next, $marks) : null,
            $marks < 0 && $c->lineStart && $c->kinds !== [] ? self::lineOpenerAt($value, $c) : -1,
        ];
    }

    /**
     * C2-W-088: the kind of an escaped opener: its character, a braced opener with its mark, the second hyphen of a
     * run, or the line kind of a block opener.
     */
    private static function kindOf(string $value, int $i, bool $line, int $after): string
    {
        $ch = $value[$i];
        if ($line && !self::taskBox($value, $i)) {
            // The second hyphen of a hyphen run and the delimiter of an ordered marker stand for the line.
            return 'line' . ($ch === '-' || ctype_punct($value[0]) ? $value[0] : $ch);
        }
        if ($ch === '{' && $after > 0 && $after < 0x80 && self::isPunct(chr($after))) {
            return '{' . chr($after);
        }

        return $ch === '-' ? '--' : $ch;
    }

    /**
     * A task box at the start of an item's text, `[ ]` or `[x]` before a space or the end, reports the kind `[`; with
     * a bracket right behind its space it does not, as carve-js and carve-rs write it.
     */
    private static function taskBox(string $value, int $i): bool
    {
        return preg_match('/\G\[[ xX]\](?:$|[ \t]+(?!\[))/', $value, $m, 0, $i) === 1;
    }

    /**
     * C2-W-088: a place where an opener of a kind of the leaf stands unescaped.
     */
    private static function potential(string $value, int $i, int $prev, int $after, Context $c, int $lineOpener): bool
    {
        $ch = $value[$i];
        if ($lineOpener === $i) {
            return true;
        }

        return match (true) {
            $ch === '{' => ($after > 0 && $after < 0x80 && isset($c->kinds['{' . chr($after)]))
                || (isset($c->kinds['{']) && $prev !== 0x7B),
            $ch === '-' => isset($c->kinds['--']) && self::secondHyphen($value, $i, $prev, $c),
            // C2-I-055: the mark of a braced opener before the text makes no run with it.
            $ch === '/' || $ch === '*' => isset($c->kinds[$ch]) && ($prev !== ord($ch) || ($i === 0 && !$c->afterText))
                && ($value[$i + 1] ?? '') !== $ch
                && !Chars::isWord($prev) && !Chars::isSpace($after),
            // C2-W-125 case five: a `]` closes only inside a bracketed node, so outside one it is no potential closer.
            $ch === ']' => $c->inBracket && isset($c->kinds[']']) && ($prev !== 0x5D || ($i === 0 && !$c->afterText)),
            default => isset($c->kinds[$ch]) && ($prev !== ord($ch) || ($i === 0 && !$c->afterText)),
        };
    }

    /**
     * C2-W-088: the second hyphen of a run of unescaped hyphens.
     */
    private static function secondHyphen(string $value, int $i, int $prev, Context $c): bool
    {
        if ($i === 0) {
            return $prev === 0x2D && $c->hyphensBefore === 1;
        }
        if ($value[$i - 1] !== '-' || ($c->escapedAt[$i - 1] ?? false)) {
            return false;
        }
        if ($i === 1) {
            return $c->last !== 0x2D || $c->lineStart;
        }

        return $value[$i - 2] !== '-' || ($c->escapedAt[$i - 2] ?? false);
    }

    /**
     * C2-W-088, C2-W-090: where a line of the leaf with a reported line kind takes its escape: the first character,
     * the second hyphen of a hyphen run, or the delimiter of an ordered marker; -1 for none.
     */
    private static function lineOpenerAt(string $value, Context $c): int
    {
        $first = $value[0];
        if (isset($c->kinds['line' . $first])) {
            return $first === '-' && ($value[1] ?? '') === '-' ? 1 : 0;
        }
        $at = strspn($value, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $delimiter = $value[$at] ?? '';

        return $at > 0 && $at <= 9 && ($delimiter === '.' || $delimiter === ')') && isset($c->kinds['line' . $delimiter]) ? $at : -1;
    }

    /**
     * Bare text runs with different lengths do not close each other. Plan escapes from right to left so an escaped
     * prefix cannot make an earlier run acquire a closer (C2-W-088, C2-W-091).
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next The character after the text.
     * @param int $marks The offset the line start escapes, or -1.
     *
     * @return array<int, true>|null Null escapes every backtick.
     */
    private static function tickEscapes(string $value, Context $c, int $next, int $marks): ?array
    {
        if (!str_contains($value, '`')) {
            return null;
        }
        if (self::leafTickPlan($c) !== null) {
            return self::plannedTicks($c, $marks);
        }
        $runs = [];
        preg_match_all('/`+/', $value, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$run, $at]) {
            $runs[] = [$at, strlen($run)];
        }
        $lead = self::leadTick($value, $c, $marks);
        if (count($runs) < 2) {
            return self::singleRunEscapes($value, $runs[0], $c, $next, $lead);
        }
        $c->neighborTick ??= self::hasNeighborTick($c);
        if ($c->neighborTick) {
            return null;
        }

        $firsts = $c->tickReportedOnly ? [] : array_column($runs, 0);

        $end = strlen($value);

        return self::tickRounds(
            [...$runs, ...self::verbatimRunsBehind($value, $c)],
            $firsts,
            $lead >= 0 ? [$lead => true] : [],
            static fn (int $at): bool => $at >= $end,
        );
    }

    /**
     * C2-W-088: the backtick runs in the spelling of the verbatim nodes behind the text, at offsets past its end. A
     * rest of the text's runs can pair with the opening run of such a node, which takes no escape. None where the
     * text has no place in the leaf or a later text holds a backtick.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<array{int, int}>
     */
    private static function verbatimRunsBehind(string $value, Context $c): array
    {
        $flat = (string)$c->flat;
        $from = $c->here + strlen($value);
        if ($c->here < 0 || str_contains(str_replace("\0", '', substr($flat, $from)), '`')) {
            return [];
        }
        $runs = [];
        $at = strlen($value) + 1;
        foreach ($c->flatTypes as $offset => $type) {
            if ($offset < $from || !in_array($type, self::TICK_ATOMS, true)) {
                continue;
            }
            $code = $c->flatNodes[$offset]['value'] ?? null;
            if (!is_string($code)) {
                return [];
            }
            preg_match_all('/`+/', Inline::codeSpan($code), $matches);
            foreach ($matches[0] as $run) {
                $runs[] = [$at, strlen($run)];
                $at += strlen($run) + 1;
            }
        }

        return $runs;
    }

    /**
     * The backticks of the text by its leaf's plan. A tick the line start of this text escapes stops a pair the plan
     * read across it, so the plan is made again with it.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $marks
     *
     * @return array<int, true>
     */
    private static function plannedTicks(Context $c, int $marks): array
    {
        $forced = $c->tickForced;
        foreach ([$marks, ...array_keys($c->behindMarker)] as $at) {
            $global = $c->tickAt[$c->textPath][$at] ?? null;
            if ($global !== null) {
                $forced[$global] = true;
            }
        }
        if ($forced !== $c->tickForced) {
            $c->tickForced = $forced;
            $c->tickPlan = null;
        }

        return self::leafTickPlan($c)[$c->textPath] ?? [];
    }

    /**
     * C2-W-092, C2-W-088: the backtick that a line opener or an opener behind a marker takes, or -1.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $marks
     *
     * @return int
     */
    private static function leadTick(string $value, Context $c, int $marks): int
    {
        $lead = $marks >= 0 && $value[$marks] === '`' ? $marks : -1;
        foreach (array_keys($c->behindMarker) as $at) {
            $lead = $value[$at] === '`' ? $at : $lead;
        }

        return $lead;
    }

    /**
     * C2-W-088: the rounds of the backtick kind. Each code span the runs open reports its opener; the first report
     * also escapes every potential opener of the kind, `$firsts`, none where only the reported openers are escaped. A
     * rest that still opens a span is reported in a later round.
     *
     * @param array<array{int, int}> $runs
     * @param array<int> $firsts
     * @param array<int, true> $escaped
     * @param \Closure(int): bool|null $fixed Whether the tick at an offset is a verbatim node's, which takes no escape.
     * @param array<int, true> $shielded
     *
     * @return array<int, true>
     */
    private static function tickRounds(array $runs, array $firsts, array $escaped, ?Closure $fixed = null, array $shielded = []): array
    {
        $kind = $firsts === [];
        while (($open = self::spanOpeners($runs, $escaped, $fixed, $shielded)) !== []) {
            if (!$kind) {
                $kind = true;
                $escaped += array_fill_keys($firsts, true);
                self::$tickKindRounds++;
            }
            $escaped += array_fill_keys($open, true);
        }

        return $escaped;
    }

    /**
     * Whether the leaf of `$c` has a backtick plan, which the bracketed content inside it then reads too.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    public static function hasTickPlan(Context $c): bool
    {
        return self::leafTickPlan($c) !== null;
    }

    /**
     * C2-W-088: the backticks that take a backslash in each text of a leaf whose backticks stand only in text, in
     * paired and braced spans included, so a code span can pair runs of different texts. Null for another leaf.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<string, array<int, true>>|null
     */
    private static function leafTickPlan(Context $c): ?array
    {
        if ($c->tickPlan !== null) {
            return $c->tickPlan === false ? null : $c->tickPlan;
        }
        $c->tickPlan = false;
        $c->tickAt = [];
        $flat = '';
        $owners = [];
        $shielded = [];
        $atoms = [];
        if (
            $c->leafNodes === null || !self::planNodes($c->leafNodes, $c->leafPath, $flat, $owners, true, $shielded, $atoms)
            || $owners === []
        ) {
            return null;
        }
        foreach ($owners as $at => [$path, $offset]) {
            $c->tickAt[$path][$offset] = $at;
        }
        $runs = [];
        $firsts = [];
        $escaped = $c->tickForced;
        $gluedSeen = false;
        preg_match_all('/`+/', $flat, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$run, $at]) {
            $length = strlen($run);
            $runs[] = [$at, $length];
            $owned = array_intersect_key($owners, array_flip(range($at, $at + $length - 1)));
            if ($owned !== [] && count($owned) < $length) {
                // C2-W-125 case six: a tick of text glued behind the closing fence of a verbatim node always takes
                // the backslash, which ends the fence there. A tick glued before an opening fence is not measured.
                if (isset($owners[$at]) || array_key_last($owned) !== $at + $length - 1) {
                    return null;
                }
                $escaped[array_key_first($owned)] = true;
                $gluedSeen = true;
            }
            foreach ($owned as $k => [$path]) {
                if ($k === $at || !isset($owners[$k - 1]) || $path !== $owners[$k - 1][0]) {
                    $firsts[] = $k;
                }
            }
        }
        // C2-W-088: where the bare text pairs the runs otherwise than the verbatim nodes, its parse holds a code span
        // the tree does not, which reports the kind: every first tick of a text run takes the backslash.
        if ($gluedSeen && !$c->tickReportedOnly && self::barePairsOtherwise($flat, $atoms, $shielded)) {
            $escaped += array_fill_keys($firsts, true);
            self::$tickKindRounds++;
        }
        if (!$c->inLineBlock) {
            $escaped += self::fenceLineTicks($flat, $owners);
        }
        $plan = [];
        $fixed = static fn (int $at): bool => !isset($owners[$at]);
        $firsts = $c->tickReportedOnly ? [] : $firsts;
        foreach (array_keys(self::tickRounds($runs, $firsts, $escaped, $fixed, $shielded)) as $at) {
            if (!isset($owners[$at])) {
                continue;
            }
            [$path, $offset] = $owners[$at];
            $plan[$path][$offset] = true;
        }
        $c->tickShielded = self::unshielded($shielded, $owners, [$runs, $firsts, $escaped, $fixed]);
        $c->tickPlan = $plan;

        return $plan;
    }

    /**
     * Where the writer escapes the brace of a literal block, its backticks take the plan without the shield.
     *
     * @param array<int, true> $shielded
     * @param array<int, array{string, int}> $owners
     * @param array{array<array{int, int}>, array<int>, array<int, true>, \Closure(int): bool} $rounds The arguments of the
     *   plan's rounds.
     *
     * @return array<string, array<int, true>>
     */
    private static function unshielded(array $shielded, array $owners, array $rounds): array
    {
        if ($shielded === []) {
            return [];
        }
        $bare = self::tickRounds(...$rounds);
        $out = [];
        foreach (array_keys($shielded) as $at) {
            if (isset($bare[$at], $owners[$at])) {
                [$path, $offset] = $owners[$at];
                $out[$path][$offset] = true;
            }
        }

        return $out;
    }

    /**
     * Appends the text of `$nodes` to `$flat`, a break as a line feed and other markup as `\1`, and records the text
     * and offset of each backtick. False where a verbatim node or another node that holds a backtick stands in it.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param string $flat
     * @param array<int, array{string, int}> $owners
     * @param bool $atoms Whether a code span, math or literal node stands in by its spelling, whose ticks have no owner.
     * @param array<int, true> $shielded
     * @param array<array{int, int}> $atomSpans The offsets of the first and past the last tick of each such spelling.
     *
     * @return bool
     */
    private static function planNodes(
        array $nodes,
        string $path,
        string &$flat,
        array &$owners,
        bool $atoms = false,
        array &$shielded = [],
        array &$atomSpans = [],
    ): bool {
        foreach ($nodes as $k => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            $value = $node['value'] ?? null;
            if ($type === 'text' && is_string($value)) {
                self::shieldTicks($value, strlen($flat), $flat !== '' && $flat[-1] === "\1", $shielded);
                for ($i = strpos($value, '`'); $i !== false; $i = strpos($value, '`', $i + 1)) {
                    $owners[strlen($flat) + $i] = [$path . '/' . $k, $i];
                }
                $flat .= $value;
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                $flat .= "\n";
            } elseif (is_string($type) && isset(self::PLAN_SPANS[$type]) && is_array($node['children'] ?? null)) {
                if (str_contains(Inline::strings(array_diff_key($node, ['children' => true])), '`')) {
                    return false;
                }
                $flat .= "\1";
                if (!self::planNodes($node['children'], $path . '/' . $k . '/children', $flat, $owners, $atoms, $shielded, $atomSpans)) {
                    return false;
                }
                $flat .= "\1";
            } elseif ($atoms && self::plannedAtom($node) !== null) {
                $spelled = (string)self::plannedAtom($node);
                $atomSpans[] = [strlen($flat) + (int)strpos($spelled, '`'), strlen($flat) + (int)strrpos($spelled, '`') + 1];
                $flat .= $spelled;
            } elseif (in_array($type, self::TICK_ATOMS, true) || str_contains(Inline::strings($node), '`')) {
                return false;
            } else {
                $flat .= "\1";
            }
        }

        return true;
    }

    /**
     * C2-W-092: a fence line takes the backslash before its first backtick.
     *
     * @param string $flat
     * @param array<int, array{string, int}> $owners
     *
     * @return array<int, true>
     */
    private static function fenceLineTicks(string $flat, array $owners): array
    {
        $escaped = [];
        $start = 0;
        foreach (explode("\n", $flat) as $line) {
            $lead = strspn($line, " \t");
            if (($line[$lead] ?? '') === '`' && isset($owners[$start + $lead]) && LineShapes::isFenceLine($line, $lead, strlen($line))) {
                $escaped[$start + $lead] = true;
            }
            $start += strlen($line) + 1;
        }

        return $escaped;
    }

    /**
     * C2-I-015: whether the backtick runs of the bare `$flat` pair otherwise than the verbatim spellings at `$atomSpans`
     * stand. A run behind a backslash of text starts one tick later; a run in a literal attribute block opens nothing.
     *
     * @param string $flat
     * @param array<array{int, int}> $atomSpans
     * @param array<int, true> $shielded
     *
     * @return bool
     */
    private static function barePairsOtherwise(string $flat, array $atomSpans, array $shielded): bool
    {
        $inAtom = static function (int $at) use ($atomSpans): bool {
            foreach ($atomSpans as [$from, $to]) {
                if ($at >= $from && $at < $to) {
                    return true;
                }
            }

            return false;
        };
        $runs = [];
        preg_match_all('/`+/', $flat, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$run, $at]) {
            if (isset($shielded[$at])) {
                continue;
            }
            $end = $at + strlen($run);
            $slashed = $at > 0 && $flat[$at - 1] === '\\' && !$inAtom($at) && !$inAtom($at - 1);
            $runs[] = [$slashed ? $at + 1 : $at, $end];
        }
        $held = [];
        foreach ($atomSpans as [$from, $to]) {
            $held[$from . ':' . $to] = true;
        }
        $count = count($runs);
        for ($open = 0; $open < $count; $open++) {
            [$start, $end] = $runs[$open];
            if ($start === $end) {
                continue;
            }
            for ($close = $open + 1; $close < $count; $close++) {
                if ($runs[$close][1] - $runs[$close][0] === $end - $start) {
                    if (!isset($held[$start . ':' . $runs[$close][1]])) {
                        return true;
                    }
                    $open = $close;

                    break;
                }
            }
        }

        return false;
    }

    /**
     * The spelling of a code span, math or literal node without attributes, whose ticks a leaf plan reads but does not
     * escape; null for another node.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function plannedAtom(array $node): ?string
    {
        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TICK_ATOMS, true) || isset($node['attrs'])) {
            return null;
        }

        return self::verbatimSpelling($node, $type);
    }

    /**
     * C2-I-035: an escaped brace starts no attribute block, so the backticks the plan shielded inside it are live.
     *
     * @param string $value
     * @param int $i The escaped brace.
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<int, true>
     */
    private static function liveTicks(string $value, int $i, Context $c): array
    {
        $shielded = $c->tickShielded[$c->textPath] ?? [];
        $end = $shielded === [] ? -1 : self::literalBlockEnd($value, $i);
        $live = [];
        foreach (array_keys($shielded) as $k) {
            if ($k > $i && $k < $end) {
                $live[$k] = true;
            }
        }

        return $live;
    }

    /**
     * C2-I-005, C2-I-035: a backtick inside a literal attribute block of the text opens no code span, as the block is
     * an atom; it can still close a span opened before it. A block glued behind a node may attach and is not trusted.
     *
     * @param string $value
     * @param int $offset Where the text starts in the plan.
     * @param bool $behindNode
     * @param array<int, true> $shielded
     *
     * @return void
     */
    private static function shieldTicks(string $value, int $offset, bool $behindNode, array &$shielded): void
    {
        if (!str_contains($value, '`')) {
            return;
        }
        for ($i = strpos($value, '{'); $i !== false; $i = strpos($value, '{', $i + 1)) {
            $end = $i === 0 && $behindNode ? -1 : self::literalBlockEnd($value, $i);
            if ($end < 0) {
                continue;
            }
            for ($k = strpos($value, '`', $i); $k !== false && $k < $end; $k = strpos($value, '`', $k + 1)) {
                $shielded[$offset + $k] = true;
            }
            $i = $end - 1;
        }
    }

    /**
     * The first ticks of the code spans that the text's backtick runs open when the ticks in `$escaped` take a
     * backslash. An escaped tick starts a closer run of its own, since a span's content keeps the backslash.
     *
     * @param array<array{int, int}> $runs
     * @param array<int, true> $escaped
     * @param \Closure(int): bool|null $fixed Whether the tick at an offset takes no escape; such an opener pairs but is
     *   not reported.
     * @param array<int, true> $shielded
     *
     * @return array<int>
     */
    private static function spanOpeners(array $runs, array $escaped, ?Closure $fixed = null, array $shielded = []): array
    {
        $parts = [];
        foreach ($runs as [$at, $length]) {
            $start = $at;
            for ($k = $at + 1; $k < $at + $length; $k++) {
                if (isset($escaped[$k])) {
                    $parts[] = [$start, $k - $start];
                    $start = $k;
                }
            }
            $parts[] = [$start, $at + $length - $start];
        }
        $openers = [];
        $count = count($parts);
        for ($p = 0; $p < $count; $p++) {
            [$start, $length] = $parts[$p];
            $skip = isset($escaped[$start]) ? 1 : 0;
            if ($length - $skip === 0 || isset($shielded[$start + $skip])) {
                continue;
            }
            for ($q = $p + 1; $q < $count; $q++) {
                if ($parts[$q][1] === $length - $skip) {
                    if ($fixed === null || !$fixed($start + $skip)) {
                        $openers[] = $start + $skip;
                    }
                    $p = $q;

                    break;
                }
            }
        }

        return $openers;
    }

    /**
     * C2-W-088: the escapes of a text that holds one backtick run. Glued to a backtick outside the text it takes the
     * backslashes. Where no run of the leaf's text has a run of its length behind it, no run pairs and all stay bare.
     * The only run of the leaf's text is escaped as far as the verbatim nodes behind it close it.
     *
     * @param string $value
     * @param array{int, int} $run
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param int $marks
     *
     * @return array<int, true>|null
     */
    private static function singleRunEscapes(string $value, array $run, Context $c, int $next, int $marks): ?array
    {
        [$at, $length] = $run;
        if ($c->flat === null || ($at === 0 && $c->last === 0x60) || ($at + $length === strlen($value) && $next === 0x60)) {
            return null;
        }
        $c->tickRuns ??= self::tickRuns($c);
        if (!self::tickPairs($c->tickRuns)) {
            // C2-W-092: behind the backslash of a line opener the rest of the run is shorter by one.
            return $marks === $at && self::runBehind($c, $at + $length, $length - 1) ? null : [];
        }
        $c->neighborTick ??= self::hasNeighborTick($c);

        return !$c->neighborTick && $c->here >= 0 && substr_count((string)$c->flat, '`') === $length
            ? self::loneRunEscapes($at, $length, $c) : null;
    }

    /**
     * The backtick runs of the leaf in order, its own text's and those in the spelling of each other node.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<array{int, int, bool}>
     */
    private static function tickRuns(Context $c): array
    {
        $flat = (string)$c->flat;
        $runs = [];
        $n = strlen($flat);
        for ($i = 0; $i < $n; $i++) {
            if ($flat[$i] === '`') {
                $length = strspn($flat, '`', $i);
                $runs[] = [$i, $length, true];
                $i += $length - 1;
            } elseif (isset($c->flatNodes[$i])) {
                self::nodeTickRuns($c->flatNodes[$i], $i, $runs);
            }
        }

        return $runs;
    }

    /**
     * Appends the backtick runs of the spelling of `$node`: those of its text, and the fence and value of a verbatim
     * node, which takes no escape. A verbatim node without a string value counts as a tick of its own.
     *
     * @param array<mixed> $node
     * @param int $offset
     * @param array<array{int, int, bool}> $runs
     *
     * @return void
     */
    private static function nodeTickRuns(array $node, int $offset, array &$runs): void
    {
        $type = $node['type'] ?? null;
        $value = $node['value'] ?? null;
        $verbatim = in_array($type, self::TICK_ATOMS, true);
        if ($type === 'text' || $verbatim) {
            $source = !$verbatim ? (is_string($value) ? $value : '') : Inline::codeSpan(is_string($value) ? $value : '`');
            if (preg_match_all('/`+/', $source, $matches)) {
                foreach ($matches[0] as $run) {
                    $runs[] = [$offset, strlen($run), !$verbatim];
                }
            }

            return;
        }
        foreach ((array)($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                self::nodeTickRuns($child, $offset, $runs);
            }
        }
    }

    /**
     * Whether a run of text has a run of the same length behind it, where a right-to-left plan of the runs escapes one
     * (C2-W-088, C2-W-091).
     *
     * @param array<array{int, int, bool}> $runs
     *
     * @return bool
     */
    private static function tickPairs(array $runs): bool
    {
        $later = [];
        for ($r = count($runs) - 1; $r >= 0; $r--) {
            [, $length, $text] = $runs[$r];
            if ($text && isset($later[$length])) {
                return true;
            }
            $later[$length] = true;
        }

        return false;
    }

    /**
     * Whether the leaf holds a run of `$length` behind offset `$end` of this text; true where the text has no offset.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $end
     * @param int $length
     *
     * @return bool
     */
    private static function runBehind(Context $c, int $end, int $length): bool
    {
        if ($c->here < 0) {
            return true;
        }
        foreach ((array)$c->tickRuns as [$offset, $run]) {
            if ($offset >= $c->here + $end && $run === $length) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-088, C2-W-091: the backticks of the leaf's only text run that take a backslash. A run of a verbatim node
     * behind it closes the run, so its first ticks are escaped while such runs of the remaining lengths follow. Null
     * where a node behind it has no value to measure.
     *
     * @param int $at
     * @param int $length
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<int, true>|null
     */
    private static function loneRunEscapes(int $at, int $length, Context $c): ?array
    {
        $later = [];
        $from = $c->here + $at;
        foreach ($c->flatTypes as $offset => $type) {
            if ($offset < $from || !in_array($type, self::TICK_ATOMS, true)) {
                continue;
            }
            $value = $c->flatNodes[$offset]['value'] ?? null;
            if (!is_string($value)) {
                return null;
            }
            preg_match_all('/`+/', Inline::codeSpan($value), $matches);
            foreach ($matches[0] as $run) {
                $later[strlen($run)] = true;
            }
        }
        $escaped = [];
        for ($count = 0; $count < $length && isset($later[$length - $count]); $count++) {
            $escaped[$at + $count] = true;
        }

        return $escaped;
    }

    private static function hasNeighborTick(Context $c): bool
    {
        foreach ($c->flatNodes as $node) {
            $type = $node['type'] ?? null;
            if (is_string($type) && !isset(self::WHOLE[$type]) && str_contains(Inline::strings($node), '`')) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-146: the text `$value` written with the backslashes the pair rules of lint ask for on its spelling, each
     * mark of a reported opening run escaped, until they ask for none. `$c` is the context before the text.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    public static function escapePairs(string $value, Context $c, int $next): string
    {
        if ($c->writerState?->repairTrace !== null) {
            return self::escape($value, $c, $next);
        }
        $start = clone $c;
        $forced = $c->runPairEscapes[$c->textPath] ?? [];
        $c->pairEscapes = $forced;
        $out = self::escape($value, $c, $next);
        $c->pairEscapes = [];
        for ($round = 0; $round < 64; $round++) {
            $asked = self::pairOpeners($out, $c->escapedAt, $value);
            $asked = array_diff_key($asked, $forced);
            if ($asked === []) {
                break;
            }
            if ($round === 0) {
                $asked = self::settledPairEscapes($value, $c->escapedAt, $forced, $asked, $round);
            }
            $forced += $asked;
            $again = clone $start;
            $again->pairEscapes = $forced;
            // The first write charged the reparse of its first line; the same line again is no new repair state.
            $again->writerState = null;
            $out = self::escape($value, $again, $next);
            foreach (get_object_vars($again) as $name => $field) {
                $c->{$name} = $field;
            }
            $c->pairEscapes = [];
            $c->writerState = $start->writerState;
        }

        return $out;
    }

    /**
     * The escapes of the rounds of `escapePairs()` from `$asked` on, settled on the spelling alone (carve-php#5704).
     *
     * Each round escapes one opener per pair, so the next mark pairs anew and the rounds halve. The offsets the pair
     * rules ask for are `_`, `~`, `=` or `*` bytes, and the escape loop reads its own escapes and the pair escapes only
     * at `-`, `{` and `:` (secondHyphen, run, markEscaped, innerColons). A pair escape therefore only adds its own
     * backslash, and the write of a round is the previous write with those backslashes added.
     *
     * @param-out int $round
     *
     * @param string $value
     * @param array<int, bool> $escaped Whether each byte offset of `$value` took a backslash in the first write.
     * @param array<int, mixed> $forced
     * @param array<int, bool> $asked What the first write asks for, not in `$forced`.
     * @param int $round Set to the last round the settled escapes stand for.
     *
     * @return array<int, bool> Every offset the rounds ask for, not in `$forced`.
     */
    private static function settledPairEscapes(string $value, array $escaped, array $forced, array $asked, int &$round): array
    {
        $all = [];
        while (true) {
            $all += $asked;
            $forced += $asked;
            foreach ($asked as $i => $_) {
                $escaped[$i] = true;
            }
            if ($round >= 63) {
                return $all;
            }
            $out = '';
            $at = 0;
            ksort($escaped);
            foreach ($escaped as $i => $on) {
                if ($on) {
                    $out .= substr($value, $at, $i - $at) . '\\';
                    $at = $i;
                }
            }
            $out .= substr($value, $at);
            $asked = array_diff_key(self::pairOpeners($out, $escaped, $value), $forced);
            if ($asked === []) {
                return $all;
            }
            $round++;
        }
    }

    /**
     * C2-W-146: the offsets the pair rules ask a backslash for in the text nodes of `$tree` as the rounds spell them
     * with `$escapes`, by path, each run of text and soft breaks tested whole.
     *
     * @param array<mixed> $tree
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<string, array<int, true>>
     */
    public static function treePairOpeners(array $tree, array $escapes): array
    {
        $asked = [];
        $pending = [['', $tree]];
        while ($pending !== []) {
            [$path, $node] = array_pop($pending);
            foreach ($node as $key => $list) {
                if (!is_array($list) || !array_is_list($list)) {
                    continue;
                }
                $run = [];
                foreach ([...$list, null] as $at => $child) {
                    $type = is_array($child) ? ($child['type'] ?? null) : null;
                    $childPath = $path . '/' . $key . '/' . $at;
                    if ($type === 'text' && is_string($child['value'] ?? null)) {
                        $run[] = self::spelledEntry($childPath, $child['value'], $escapes[$childPath] ?? []);

                        continue;
                    }
                    if ($type === 'soft_break') {
                        $run[] = new RunEntry($childPath, "\n", 1, [], '');

                        continue;
                    }
                    self::askRunPairs($run, $asked);
                    $run = [];
                    if (is_array($child)) {
                        $pending[] = [$childPath, $child];
                    }
                }
            }
        }

        return $asked;
    }

    /**
     * The text node `$value` as `RepairTrace::bareText()` spells it with the rounds' escapes `$offsets`.
     *
     * @param string $path
     * @param string $value
     * @param array<int, true> $offsets
     */
    private static function spelledEntry(string $path, string $value, array $offsets): RunEntry
    {
        $out = '';
        $escaped = [];
        $n = strlen(rtrim($value, "\r\n"));
        for ($i = 0; $i < $n; $i++) {
            // An escape and the doubled literal backslash are placed apart, as bareText() places them.
            $count = (int)isset($offsets[$i]) + (int)($value[$i] === '\\' && $i + 1 < $n && self::isPunct($value[$i + 1]));
            if ($count > 0) {
                $out .= str_repeat('\\', $count);
                $escaped[$i] = $count;
            }
            $out .= $value[$i];
        }

        return new RunEntry($path, $out . substr($value, $n), 0, $escaped, $value);
    }

    /**
     * Adds to `$asked` what the pair rules ask of one run of text nodes and soft breaks.
     *
     * @param array<\MarkupCarve\Carve\Writer\RunEntry> $run
     * @param array<string, array<int, true>> $asked
     */
    private static function askRunPairs(array $run, array &$asked): void
    {
        $texts = array_values(array_filter($run, static fn (RunEntry $entry): bool => $entry->value !== ''));
        if ($texts === []) {
            return;
        }
        $found = count($run) === 1
            ? [$texts[0]->path => self::pairOpeners($texts[0]->out, $texts[0]->escaped, $texts[0]->value)]
            : self::runPairOpeners($run);
        foreach ($found as $path => $offsets) {
            foreach ($offsets as $i => $on) {
                if ($on) {
                    $asked[$path][$i] = true;
                }
            }
        }
    }

    /**
     * C2-W-146: the offsets of the text nodes of each run of text and soft breaks in `$log` that the pair rules ask a
     * backslash for, by path.
     *
     * @param array<\MarkupCarve\Carve\Writer\RunEntry> $log
     *
     * @return array<string, array<int, bool>>
     */
    public static function runPairOpeners(array $log): array
    {
        // The run of each entry by number, so a long paragraph of lone texts builds no array per entry (carve-php#5664).
        $runOf = [];
        $sizes = [];
        $lastIndex = [];
        $lastRun = [];
        foreach ($log as $entry) {
            $slash = strrpos($entry->path, '/');
            $list = substr($entry->path, 0, (int)$slash);
            $index = (int)substr($entry->path, (int)$slash + 1);
            if (isset($lastIndex[$list]) && $lastIndex[$list] === $index - 1) {
                $run = $lastRun[$list];
                $sizes[$run]++;
            } else {
                $run = count($sizes);
                $sizes[] = 1;
            }
            $runOf[] = $run;
            $lastIndex[$list] = $index;
            $lastRun[$list] = $run;
        }
        unset($lastIndex, $lastRun);
        $runs = [];
        foreach ($log as $i => $entry) {
            if ($sizes[$runOf[$i]] >= 2) {
                $runs[$runOf[$i]][] = $entry;
            }
        }
        unset($runOf, $sizes);
        ksort($runs);
        $asked = [];
        foreach ($runs as $run) {
            $source = '';
            $owners = [];
            foreach ($run as $at => $entry) {
                $source .= substr($entry->out, 0, $entry->prefix);
                $owners[$at] = strlen($source);
                $source .= substr($entry->out, $entry->prefix);
            }
            foreach (self::PAIR_RULES as [$mark, $id]) {
                $size = strlen($mark);
                foreach (NodeRulesA::pairRanges($source, $mark, $id) as [$from]) {
                    for ($k = 0; $k < $size; $k++) {
                        self::askRun($run, $owners, $from + $k, $asked);
                    }
                }
            }
        }

        return $asked;
    }

    /**
     * @param-out array<string, array<int, bool>> $asked
     *
     * @param array<\MarkupCarve\Carve\Writer\RunEntry> $run
     * @param array<int, int> $owners Where the text of each entry starts in the source of the run.
     * @param int $byte
     * @param array<string, array<int, bool>> $asked
     *
     * @return void
     */
    private static function askRun(array $run, array $owners, int $byte, array &$asked): void
    {
        foreach ($run as $at => $entry) {
            $from = $owners[$at];
            $written = substr($entry->out, $entry->prefix);
            if ($byte < $from || $byte >= $from + strlen($written) || $entry->value === '') {
                continue;
            }
            $place = self::pairOpeners($written, $entry->escaped, $entry->value, $byte - $from);
            foreach ($place as $i => $_) {
                $asked[$entry->path][$i] = true;
            }

            return;
        }
    }

    /**
     * @param string $out The text as written.
     * @param array<int, bool|int> $escaped Whether each byte offset of `$value` took a backslash, or how many.
     * @param string $value
     * @param int $only A byte of `$out` whose offset in `$value` is asked for alone, or -1 for the pairs of `$out`.
     *
     * @return array<int, bool> The offsets of `$value` that the pair rules ask a backslash for.
     */
    private static function pairOpeners(string $out, array $escaped, string $value, int $only = -1): array
    {
        if (strpbrk($out, '_~=*') === false) {
            return [];
        }
        // The offset in `$value` of each byte of `$out` that a character of the value starts.
        $place = [];
        $at = 0;
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            $at += (int)($escaped[$i] ?? 0);
            $place[$at] = $i;
            $at++;
        }
        $asked = [];
        if ($only >= 0) {
            $i = $place[$only] ?? null;
            if ($i !== null && !($escaped[$i] ?? false)) {
                $asked[$i] = true;
            }

            return $asked;
        }
        foreach (self::PAIR_RULES as [$mark, $id]) {
            $size = strlen($mark);
            foreach (NodeRulesA::pairRanges($out, $mark, $id) as [$from]) {
                for ($k = 0; $k < $size; $k++) {
                    $i = $place[$from + $k] ?? null;
                    if ($i !== null && !($escaped[$i] ?? false)) {
                        $asked[$i] = true;
                    }
                }
            }
        }

        return $asked;
    }

    /**
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param int $after
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function needs(string $value, int $i, int $prev, int $after, Context $c, int $next): bool
    {
        $ch = $value[$i];
        if (str_contains(self::ALWAYS, $ch)) {
            return true;
        }
        // C2-I-055, C2-I-060: a mark before `}` closes the node of that mark open around the text, so the mark takes the
        // backslash; with none open the closer is text.
        if (self::closesOpenMark($ch, $after, $c)) {
            return !self::emptyBracedCloser($value, $i, $c) && !self::bracedOpenerMark($value, $i, $c);
        }

        // C2-W-090: a sigil glued to the bracket or the backtick of a node may form a construct with it, as `![` an
        // image; whether it does depends on that node, so the read-back decides.
        if ($i === strlen($value) - 1 && self::gluedSigil($ch, $next)) {
            return !self::$bareSigils && self::undecided();
        }

        return match ($ch) {
            '[' => self::bracket($value, $i, $c),
            '{' => self::brace($value, $i, $c, $after, self::markEscaped($value, $i, $c, $next)),
            // C2-W-097: a backslash before ASCII punctuation, a space or a line end.
            '\\' => $after === -1 || $after === 10 || $after === 32 || ($after >= 0 && $after < 0x80 && self::isPunct(chr($after))),
            ']' => $c->inBracket,
            // C2-W-098
            '|' => $c->inCell,
            '}' => false,
            '/', '*' => self::pairs($value, $i, $prev, $after, $c),
            '-' => self::hyphen($value, $i, $c, $next),
            '.' => self::dot($value, $i, $c),
            default => self::sigil($value, $i, $prev, $after, $next, $c),
        };
    }

    /**
     * C2-I-055, C2-I-060: whether the mark `$ch` before `}` closes the node of that mark open around the text.
     */
    private static function closesOpenMark(string $ch, int $after, Context $c): bool
    {
        return $after === 0x7D && $c->marks !== '' && str_contains($c->marks, $ch);
    }

    /**
     * C2-W-094: an opening bracket of the leaf's own text forms a construct when its pair is followed by a link tail, a
     * label, `[]` or a brace block, when it follows `^` or starts `[^`, or when its label resolves as a reference. A
     * bracket inside another node is planned on its own text: one that forms a construct there takes the backslash,
     * and the read-back decides the others.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function bracket(string $value, int $i, Context $c): bool
    {
        if ($c->inBracket) {
            return true;
        }
        if ($c->here < 0) {
            // C2-I-118: near the depth cap the leaf keeps the backslash of every nested bracket.
            $flat = (string)$c->flat;
            if (self::$nestedCap === null || self::$nestedCap[0] !== $flat) {
                self::$nestedCap = [$flat, self::openDepth($flat) >= Tables::INLINE_DEPTH];
            }
            if (self::$conservative || self::$nestedCap[1]) {
                return true;
            }
            if (self::$nestedBrackets === null || self::$nestedBrackets[0] !== $value || self::$nestedBrackets[1] !== $c->labels) {
                self::$nestedBrackets = [$value, $c->labels, self::bracketPlan($value, $c->labels, [], [])];
            }

            return (self::$nestedBrackets[2][$i] ?? true) || self::undecided();
        }
        $c->brackets ??= self::brackets((string)$c->flat, $c->labels, $c->flatFirst, self::sealed($c));

        return $c->brackets[$c->here + $i] ?? true;
    }

    /**
     * The decision for every opening bracket of the leaf, from right to left: a bracket that takes a backslash pairs
     * with nothing, so its closing bracket stays open for the brackets before it. A pair across another node is not
     * known and takes the backslash.
     *
     * @param string $flat
     * @param array<string, true> $labels
     * @param array<int, int> $nodeFirst
     * @param array<int, true> $sealed
     *
     * @return array<int, bool>
     */
    private static function brackets(string $flat, array $labels, array $nodeFirst, array $sealed): array
    {
        // C2-I-118: near the depth cap a bracket that pairs nothing still counts, so the plan keeps its backslash
        // there rather than leave the bracket to the read-back.
        $conservative = self::$conservative;
        self::$conservative = $conservative || self::openDepth($flat) >= Tables::INLINE_DEPTH;
        try {
            return self::bracketPlan($flat, $labels, $nodeFirst, $sealed);
        } finally {
            self::$conservative = $conservative;
        }
    }

    /**
     * The most brackets of the flattened leaf open at once, and every node or mark that may open inside an open
     * bracket, as carve-js counts toward the cap.
     */
    private static function openDepth(string $flat): int
    {
        $open = 0;
        $most = 0;
        $inside = 0;
        $n = strlen($flat);
        for ($k = strcspn($flat, "[]\0*/{"); $k < $n; $k += 1 + strcspn($flat, "[]\0*/{", $k + 1)) {
            $ch = $flat[$k];
            if ($ch === '[') {
                $most = max($most, ++$open);
            } elseif ($ch === ']') {
                $open = max(0, $open - 1);
            } elseif ($open > 0 && ($ch === "\0" || $ch === '{' || self::canOpen($flat, $k))) {
                $inside++;
            }
        }

        return $most + $inside;
    }

    /**
     * C2-I-047: whether the `*` or `/` at `$k` of the flattened leaf may open, not after a word character and not
     * before a space or the end.
     */
    private static function canOpen(string $flat, int $k): bool
    {
        $before = $k > 0 ? ord($flat[$k - 1]) : -1;
        $after = $k + 1 < strlen($flat) ? ord($flat[$k + 1]) : -1;

        return !Chars::isWord($before) && $after !== -1 && !Chars::isSpace($after);
    }

    /**
     * @param string $flat
     * @param array<string, true> $labels
     * @param array<int, int> $nodeFirst
     * @param array<int, true> $sealed
     *
     * @return array<int, bool>
     */
    private static function bracketPlan(string $flat, array $labels, array $nodeFirst, array $sealed): array
    {
        $out = [];
        $open = [];
        $node = PHP_INT_MAX;
        $holder = PHP_INT_MAX;
        $first = PHP_INT_MAX;
        for ($k = strlen($flat) - 1; $k >= 0; $k--) {
            $ch = $flat[$k];
            if ($ch === "\0") {
                $node = $k;
                if (!isset($sealed[$k])) {
                    $holder = $k;
                }
            } elseif ($ch === ']') {
                $open[] = $k;
                $first = $k;
            } elseif ($ch === '[') {
                // C2-I-076: a footnote label ends at the first closing bracket.
                $pair = $open === [] ? PHP_INT_MAX : $open[count($open) - 1];
                // C2-I-087: a caret of the text, or the caret an inline footnote glued behind starts with.
                $caret = ($flat[$k + 1] ?? '') === '^' || (($flat[$k + 1] ?? '') === "\0" && ($nodeFirst[$k + 1] ?? -1) === 0x5E);
                $close = $caret ? $first : $pair;
                // A footnote label ends at the first `]` written, a node's own closing bracket too.
                $out[$k] = self::forms($flat, $k, $close, $close === PHP_INT_MAX && !$caret ? $holder : $node, $labels, $nodeFirst);
                if (!$out[$k] && $open !== []) {
                    array_pop($open);
                }
            }
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<int, true> The offsets of the flattened leaf that stand for a node no bracket pairs into.
     */
    private static function sealed(Context $c): array
    {
        $out = [];
        foreach ($c->flatTypes as $at => $type) {
            $node = $c->flatNodes[$at] ?? null;
            if (
                isset($c->flatAtoms[$at]) || (is_array($node) && !isset($node['attrs'])
                && ((isset(self::SEALED[$type]) && !self::openComment($node)) || !str_contains(Inline::strings($node), ']')))
            ) {
                $out[$at] = true;
            }
        }

        return $out;
    }

    /**
     * C2-I-074: a line comment runs to the line end as the bare parse reads it, so a `]` in it can close a bracket
     * before it.
     *
     * @param array<mixed> $node
     */
    private static function openComment(array $node): bool
    {
        return ($node['type'] ?? null) === 'comment' && ($node['form'] ?? null) === 'line';
    }

    /**
     * @param string $flat
     * @param int $at
     * @param int $close
     * @param int $node
     * @param array<string, true> $labels
     * @param array<int, int> $nodeFirst
     *
     * @return bool
     */
    private static function forms(string $flat, int $at, int $close, int $node, array $labels, array $nodeFirst): bool
    {
        if ($close === PHP_INT_MAX) {
            // The written form of a node behind the bracket may hold its pair.
            return $node !== PHP_INT_MAX && self::undecided();
        }
        $tail = $flat[$close + 1] ?? '';
        if ($node < $close || ($tail !== '' && str_contains("([{\0", $tail))) {
            return self::undecided();
        }
        $label = substr($flat, $at + 1, $close - $at - 1);
        if (str_starts_with($label, '^') || ($at > 0 && $flat[$at - 1] === '^')) {
            return self::undecided();
        }

        return isset($labels[Chars::fold($label)]);
    }

    /**
     * C2-W-088: a bracket the plan cannot decide is written bare, and the read-back reports it where the bare parse
     * forms a construct the tree lacks.
     */
    private static function undecided(): bool
    {
        if (self::$conservative) {
            return true;
        }
        self::$undecided = true;

        return false;
    }

    /**
     * C2-W-096, C2-I-043: an opening brace forms an attribute block glued to the node before it, a braced comment or an
     * editorial comment whose closer follows, or a braced opener whose closer follows (C2-I-055). Inside a span the
     * text has no place in the flattened leaf, and `braceInSpan` decides.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $after The first character written behind the brace.
     * @param bool $markEscaped Whether the mark behind the brace takes a backslash of its own.
     *
     * @return bool
     */
    private static function brace(string $value, int $i, Context $c, int $after, bool $markEscaped): bool
    {
        $flat = (string)$c->flat;
        $at = $c->here + $i;
        if ($c->here < 0) {
            return self::braceInSpan($value, $i, $c);
        }
        if (($flat[$at] ?? '') !== '{') {
            return true;
        }
        // C2-I-036: a valid attribute block glued to the end of a host attaches to it; behind another node, as a
        // non-breaking space, the read-back decides.
        if ($at > 0 && $flat[$at - 1] === "\0" && !isset(self::NO_HOSTS[$c->flatTypes[$at - 1] ?? ''])) {
            $spelled = self::spelledFrom($c, $at);
            if (AttributeParser::readRun($spelled, 0, strlen($spelled), new Attrs()) >= 0) {
                return isset(self::HOSTS[$c->flatTypes[$at - 1] ?? '']) || self::undecided();
            }
        }
        if (self::braceTakesNode($flat, $at, $c)) {
            return true;
        }
        $mark = $flat[$at + 1] ?? '';
        // C2-W-125 case one: a brace glued before a node reads on into the first character of the node.
        if ($mark === "\0") {
            $mark = $after >= 0 && $after < 0x80 ? chr($after) : '';
            // C2-I-060: a braced opener with no closer still takes the mark of the node, which then cannot open.
            if (!isset($c->flatAtoms[$at + 1]) && $mark !== '' && str_contains(self::MARKS . '%', $mark)) {
                $c->gluedBrace = true;

                return true;
            }
        } elseif ($markEscaped) {
            return false;
        }
        if ($mark === '' || !str_contains(self::MARKS . '%', $mark)) {
            return false;
        }

        return self::closerFollows($flat, $at + 1, $mark, $c);
    }

    /**
     * The flattened leaf from the brace at `$at` up to the first `}` outside quotes, where an attribute block would end,
     * each atom and node as written (C2-I-005: a smart quote or a code span stands in the block as its spelling).
     */
    private static function spelledFrom(Context $c, int $at): string
    {
        $flat = (string)$c->flat;
        $out = '';
        $quote = '';
        $check = 1;
        for ($n = strlen($flat); $at < $n && $flat[$at] !== "\n"; $at++) {
            $part = $flat[$at] === "\0" ? $c->flatAtoms[$at] ?? self::nodeOnLine($at, $c) ?? "\0" : $flat[$at];
            $out .= $part;
            for ($k = 0, $m = strlen($part); $k < $m; $k++) {
                $ch = $part[$k];
                if ($ch === '\\') {
                    $k++;

                    continue;
                }
                if ($quote === '' && $ch === '}') {
                    return $out;
                }
                if ($ch === '"' || $ch === "'") {
                    $quote = $quote === '' ? $ch : ($quote === $ch ? '' : $quote);
                }
            }
            // C2-W-010: a block that already failed inside the spelling fails on any longer one, so stop there.
            if (strlen($out) >= $check) {
                if (AttributeParser::read($out, 0, strlen($out), new Attrs()) < 0 && !AttributeParser::$ranOut) {
                    return $out;
                }
                $check = 2 * strlen($out);
            }
        }

        return $out;
    }

    /**
     * C2-W-088: a `{` in the text of a span is bare only where it opens nothing: no mark, brace or quote behind it, no
     * node before it to take an attribute block (C2-I-036), and no `}` or node behind it in the span, so that the rest
     * of the text with the span's closer reads as no attribute block either.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function braceInSpan(string $value, int $i, Context $c): bool
    {
        $span = $c->spanTexts[$c->textPath] ?? null;
        if ($span === null || $c->inBracket) {
            return true;
        }
        $next = $value[$i + 1] ?? '';
        $rest = substr($value, $i);
        if (self::emptyPairAt($rest, $i === 0 && $span[2])) {
            return false;
        }
        if ($i === 0 && $span[2]) {
            return true;
        }
        // A node behind the text may supply the closer, or keep the brace from forming; the read-back decides.
        $later = self::laterSpanText($span, $next);
        if ($later === null) {
            return $i < strlen($value) - 1 || self::undecided();
        }
        $probe = $rest . $span[0] . '}';
        // C2-I-043: a braced opener needs its closer behind at least one character, a comment none. A brace before `/`
        // or `*` takes the mark from the bare pairs (C2-I-060), so the pairing escapes decide those.
        $marks = '_~=+-^,#%';
        // C2-I-057, C2-W-125 case one: glued before the span's own closer, the opener takes that closer and holds
        // nothing, so the span does not form.
        if (self::gluedBeforeOwnCloser($value, $i, $span, $later)) {
            // C2-W-088: a backslash of C2-W-125 gives no kind.
            $c->gluedBrace = true;

            return true;
        }
        if ($next !== '' && str_contains($marks, $next) && !str_contains(substr($probe, $next === '%' ? 2 : 3), $next . '}')) {
            return false;
        }
        // C2-I-036: a whole attribute block of the text behind no node is literal, an empty one too.
        if (self::literalBlockEnd($value, $i, true) > $i) {
            return false;
        }
        // A brace before another brace opens nothing itself; the later brace decides.
        if ($next === '{') {
            return false;
        }
        // C2-I-043, C2-I-051, C2-I-060: a bare brace before `/` or `*` with no closer of that mark behind at least one
        // character in the span opens nothing: the span's closer drops it, so C2-W-088 places no backslash there.
        $closerProbe = $rest . $later . ($span[3] || $span[4] === 0x7D ? $span[0] . '}' : $span[0]);
        if (($next === '*' || $next === '/') && self::lastCloser($closerProbe, $next) < 3) {
            return false;
        }
        if ($next === '' || str_contains(self::MARKS . '%{}"\'\\[`<$@:&!', $next) || str_contains($rest, '}')) {
            return true;
        }

        return AttributeParser::readRun($probe, 0, strlen($probe), new Attrs()) >= 0;
    }

    /**
     * C2-I-057, C2-W-125 case one: whether the brace at `$i` stands glued before the closer of its own span: it ends
     * the text with no node behind, and reads on into that closer, or it stands before the last character, the span's
     * mark, and its opener takes the closer holding nothing.
     *
     * @param string $value
     * @param int $i
     * @param array{0: string, 1: bool, 2: bool, 3: bool, 4: int, 5: array<mixed>} $span
     * @param string $later The texts behind the text, '' where no node stands behind it.
     */
    private static function gluedBeforeOwnCloser(string $value, int $i, array $span, string $later): bool
    {
        $next = $value[$i + 1] ?? '';
        if ($later !== '') {
            return false;
        }
        if ($next === '') {
            return true;
        }
        if ($next !== $span[0] || $i + 2 !== strlen($value)) {
            return false;
        }

        return $next === '*' || $next === '/' ? $span[3] : $next !== '%' && ($i === 0 || $value[$i - 1] !== $next);
    }

    /**
     * C2-I-043, C2-I-057: whether `$rest` starts with an empty pair `{//}`, which takes its own closer and forms
     * nothing whatever stands around it, unless it reads as an attribute block of a node before it (C2-I-036).
     */
    private static function emptyPairAt(string $rest, bool $afterNode): bool
    {
        $next = $rest[1] ?? '';
        if ($next === '' || $next === '%' || $next === '#' || !str_contains(self::MARKS, $next) || substr($rest, 2, 2) !== $next . '}') {
            return false;
        }

        return !$afterNode || AttributeParser::readRun(substr($rest, 0, 4), 0, 4, new Attrs()) < 0;
    }

    /**
     * The texts of the siblings behind a span text, led by `\0`, where a brace before `/` or `*` reads its closer; ''
     * without siblings behind, and null where a sibling spells that mark bare or the brace opens another mark.
     *
     * @param array{0: string, 1: bool, 2: bool, 3: bool, 4: int, 5: array<mixed>} $span
     * @param string $next
     */
    private static function laterSpanText(array $span, string $next): ?string
    {
        if (!$span[1]) {
            return '';
        }
        if ($next !== '*' && $next !== '/') {
            return null;
        }
        $text = Inline::laterOpenText($span[5], $next);

        return $text === null ? null : "\0" . $text;
    }

    /**
     * C2-I-043, C2-I-060: whether the closer of the mark at `$from` can follow it in the written leaf.
     *
     * @param string $flat
     * @param int $from
     * @param string $mark
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function closerFollows(string $flat, int $from, string $mark, Context $c): bool
    {
        $c->closers ??= self::closers($flat, $c);
        [$offsets, $shifts, $closes, $forced, $opened, $written] = $c->closers;
        if (($forced[$mark] ?? -1) > $from) {
            return true;
        }
        // A closer spelled by the opening mark of a later span stands only in its bare spelling: the read-back decides.
        if (($opened[$mark] ?? -1) > $from) {
            return self::undecided();
        }
        // The run starts behind the mark, which is the first character of an atom at `$from`.
        $start = self::writtenAt($from, $offsets, $shifts) + 1;
        // C2-I-043, C2-I-057: a closer glued behind the opener takes it holding nothing, so `{^^}` forms nothing.
        if ($mark !== '%' && substr($written, $start, 2) === $mark . '}') {
            return false;
        }
        // C2-I-043: a braced pair other than a comment holds at least one character, so `{==}` forms nothing.
        $last = $closes[$mark] ?? -1;

        return $last >= $start + ($mark === '%' ? 0 : 1);
    }

    /**
     * The closer facts of a leaf, found once: the flat offsets of its atoms and the growth of the leaf as written behind
     * each, where an atom is its spelling and every other node one `\0`; the last closer of each mark in the written
     * leaf; and the last node that may supply a closer of each mark itself. A later atom is read with its spelling.
     * Text inside another node writes its openers with a backslash and its closers bare, so another node supplies one
     * by a closer in its text outside brackets, by the bare closing mark of emphasis or strong before a `}` of text, or
     * by the raw body of an editorial comment. For `{#` and
     * `{%` the body is raw, so a comment or any node that holds the mark may supply it.
     *
     * @param string $flat
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: array<int>, 1: array<int>, 2: array<string, int>, 3: array<string, int>, 4: array<string, int>, 5: string}
     */
    private static function closers(string $flat, Context $c): array
    {
        $marks = str_split(self::MARKS . '%');
        $written = '';
        $offsets = [];
        $shifts = [];
        $shift = 0;
        $forced = [];
        $opened = [];
        $k = 0;
        $end = strlen($flat);
        while ($k < $end) {
            $node = strpos($flat, "\0", $k);
            if ($node === false) {
                $written .= substr($flat, $k);

                break;
            }
            $written .= substr($flat, $k, $node - $k);
            $k = $node + 1;
            if (isset($c->flatAtoms[$node])) {
                $written .= $c->flatAtoms[$node];
                $shift += strlen($c->flatAtoms[$node]) - 1;
                $offsets[] = $node;
                $shifts[] = $shift;

                continue;
            }
            $written .= "\0";
            $type = $c->flatTypes[$node] ?? '';
            $held = Inline::strings($c->flatNodes[$node] ?? []);
            $bare = ($flat[$k] ?? '') === '}' ? Inline::bareMark($type) : null;
            $opened[self::openingCloser($c->flatNodes[$node] ?? [])] = $node;
            foreach ($marks as $mark) {
                $raw = $mark === '#' || $mark === '%';
                $supplies = !Inline::known($type) || $bare === $mark
                    || ($mark === '%' && $type === 'comment')
                    || ($mark === '#' && $type === 'critic_comment')
                    || ($raw && (str_contains($held, $mark . '}')
                        || str_contains(BraceBlock::of($c->flatNodes[$node] ?? []), $mark . '}')))
                    || (!$raw && $type !== 'critic_comment'
                        && self::lastCloser(Inline::openText($c->flatNodes[$node] ?? [], $mark), $mark) >= 0);
                if ($supplies) {
                    $forced[$mark] = $node;
                }
            }
        }
        $closes = [];
        foreach ($marks as $mark) {
            $closes[$mark] = self::lastCloser($written, $mark);
        }

        return [$offsets, $shifts, $closes, $forced, $opened, $written];
    }

    /**
     * The offset of the last closer of `$mark` in the written leaf, or -1. C2-I-043: in `{/}` the mark belongs to the
     * opener, and in `{//}` the closer holds no character, so that `/}` closes nothing.
     */
    private static function lastCloser(string $written, string $mark): int
    {
        $at = strrpos($written, $mark . '}');
        while (
            $at !== false && $at > 0
            && ($written[$at - 1] === '{' || ($at > 1 && $written[$at - 1] === $mark && $written[$at - 2] === '{'))
        ) {
            $at = $at > 1 ? strrpos($written, $mark . '}', $at - 2 - strlen($written)) : false;
        }

        return $at === false ? -1 : $at;
    }

    /**
     * The bare opening mark of emphasis or strong whose text starts with `}`, which spells a closer of that mark, or ''.
     *
     * @param array<mixed> $node
     *
     * @return string
     */
    private static function openingCloser(array $node): string
    {
        $lead = is_array($node['children'] ?? null) ? $node['children'][0] ?? null : null;
        if (!is_array($lead) || ($lead['type'] ?? null) !== 'text' || !is_string($lead['value'] ?? null)) {
            return '';
        }

        return str_starts_with($lead['value'], '}') ? (string)Inline::bareMark(Inline::str($node, 'type')) : '';
    }

    /**
     * The offset in the written leaf of the flat offset `$at`.
     *
     * @param int $at
     * @param array<int> $offsets The flat offsets of the atoms, ascending.
     * @param array<int> $shifts The growth of the written leaf behind each of them.
     *
     * @return int
     */
    private static function writtenAt(int $at, array $offsets, array $shifts): int
    {
        $low = 0;
        $high = count($offsets);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($offsets[$mid] < $at) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $at + ($low > 0 ? $shifts[$low - 1] : 0);
    }

    /**
     * Whether the mark behind the brace at `$i` takes a backslash of its own, so that the brace opens nothing.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function markEscaped(string $value, int $i, Context $c, int $next): bool
    {
        $j = $i + 1;
        // A mark at the end of the text is escaped for what follows it, which a later parse may read differently.
        if ($j >= strlen($value) - 1 || !str_contains(self::MARKS, $value[$j])) {
            return false;
        }
        $after = $j + 1 < strlen($value) ? Chars::at($value, $j + 1) : $next;
        // The mark is tested as it stands behind a bare brace.
        $c->escapedAt[$i] = false;
        $escaped = self::needs($value, $j, 0x7B, $after, $c, $next);
        unset($c->escapedAt[$i]);

        return $escaped;
    }

    /**
     * Whether a `}` stands in the spelling of the node at `$node` or in the leaf behind it on its line.
     */
    private static function closeAtOrBehind(string $flat, int $node, int $end, Context $c): bool
    {
        return str_contains(substr($flat, $node + 1, $end - $node - 1), '}') || str_contains(self::nodeOnLine($node, $c) ?? '}', '}');
    }

    /**
     * C2-I-035: a valid attribute block is one atom, so one that reads on into the spelling of a later node takes that
     * node in as text. Atoms are read with their spelling; at any other node the first character of its spelling is
     * probed, where a closing brace can still follow: `{key=` before a span takes it, `{k` before a link cannot.
     *
     * @param string $flat
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function braceTakesNode(string $flat, int $at, Context $c): bool
    {
        $node = self::nextOf($flat, "\0", $at);
        if ($node === false) {
            return false;
        }
        $line = self::nextOf($flat, "\n", $at);
        if ($line !== false && $line < $node) {
            return false;
        }
        $end = $line === false ? strlen($flat) : $line;
        $run = self::asWritten(substr($flat, $at, $node - $at));
        if (AttributeParser::readRun($run, 0, strlen($run), new Attrs()) >= 0) {
            return false;
        }
        while ($node !== false && $node < $end && isset($c->flatAtoms[$node])) {
            $next = strpos($flat, "\0", $node + 1);
            $stop = $next === false || $next > $end ? $end : $next;
            $run .= $c->flatAtoms[$node] . self::asWritten(substr($flat, $node + 1, $stop - $node - 1));
            $node = $stop < $end ? $stop : false;
        }
        $valid = AttributeParser::readRun($run, 0, strlen($run), new Attrs()) >= 0;
        if ($node === false || $valid) {
            return $valid;
        }
        if (!($c->flatBraces[$node] ?? true) && !self::closeAtOrBehind($flat, $node, $end, $c)) {
            return false;
        }
        $first = $c->flatFirst[$node] ?? -1;
        $first = $first < 0 ? 'a' : (string)mb_chr($first, 'UTF-8');
        foreach (['}', '"}', "'}"] as $close) {
            $probe = $run . $first . $close;
            if (AttributeParser::readRun($probe, 0, strlen($probe), new Attrs()) === strlen($probe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `strpos($flat, $needle, $at)` for the braces of one leaf in order: the last answer holds for every offset up to it,
     * or each brace of a long leaf without a node would scan to its end.
     *
     * @param string $flat
     * @param string $needle
     * @param int $at
     *
     * @return int|false
     */
    private static function nextOf(string $flat, string $needle, int $at): int|false
    {
        static $last = [];
        [$text, $from, $found] = $last[$needle] ?? ['', PHP_INT_MAX, false];
        if ($at >= $from && ($found === false || $at <= $found) && $flat === $text) {
            return $found;
        }
        $found = strpos($flat, $needle, $at);
        $last[$needle] = [$flat, $at, $found];

        return $found;
    }

    /**
     * Text as the attribute test sees it once written: a backslash and the characters of `ALWAYS` take a backslash.
     *
     * @param string $text
     *
     * @return string
     */
    private static function asWritten(string $text): string
    {
        return addcslashes(str_replace('\\', '\\\\', $text), self::ALWAYS);
    }

    private static function gluedSigil(string $ch, int $next): bool
    {
        return ($next === 0x60 && ($ch === '!' || $ch === '$')) || ($next === 0x5B && ($ch === '!' || $ch === '^'))
            || ($next === 0x24 && $ch === '$');
    }

    /**
     * C2-W-095: a sigil before a name, an angle bracket before a target, and the runs smart typography replaces.
     *
     * @param string $value
     * @param int $i
     * @param int $prev The character before this one.
     * @param int $after The character after this one.
     * @param int $next The character after the text.
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function sigil(string $value, int $i, int $prev, int $after, int $next, Context $c): bool
    {
        return match ($value[$i]) {
            '@', '#' => !self::isLetterOrNumber($prev) && self::sigilName($value, $i, $c, $next),
            ':' => !self::isLetterOrNumber($prev) && self::colonOpens($value, $i, $c, $next),
            '<' => self::angle($value, $i, $after, $next, $c),
            '>', '!' => $after === 0x3D,
            '=' => self::ahead($value, $i + 1, 2, $c, $next) === '=>',
            '+' => $after === 0x2D && !self::plusMinusNeighbor($value, $i, $c, $next),
            '(' => strtolower(self::ahead($value, $i, 4, $c, $next)) === '(tm)',
            // C2-I-023
            '%' => !$c->inLineBlock && $after === 0x25
                && ($prev === -1 || $prev === 10 || Chars::isSpace($prev) || ($i === 0 && $c->lineStart)),
            default => false,
        };
    }

    /**
     * C2-I-117: `+-` next to another `+` or `-` of the same text stays text.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function plusMinusNeighbor(string $value, int $i, Context $c, int $next): bool
    {
        if ($i > 0 && ($value[$i - 1] === '+' || $value[$i - 1] === '-')) {
            return true;
        }
        $third = substr(self::ahead($value, $i, 3, $c, $next), 2);

        return $third === '+' || $third === '-';
    }

    /**
     * C2-W-095: `<=`, and `<--` or `<-->` but not a longer hyphen run (C2-I-115), or the `<` opens an atom.
     *
     * @param string $value
     * @param int $i
     * @param int $after
     * @param int $next
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function angle(string $value, int $i, int $after, int $next, Context $c): bool
    {
        if ($after === 0x3D) {
            return true;
        }
        if ($after === 0x2D) {
            return preg_match('/^<--(?!-)/', self::ahead($value, $i, 4, $c, $next)) === 1;
        }

        return ($after === 0x2F || ($after >= 0 && $after < 0x80 && ctype_alnum(chr($after)))) && self::angleOpens($value, $i, $next, $c);
    }

    /**
     * C2-I-073, C2-I-096, C2-I-122 to C2-I-124: the `<` opens an autolink or a cross-reference. A body that runs to the
     * end of the text may go on behind it, so that `<` keeps the backslash unless nothing follows.
     *
     * @param string $value
     * @param int $i
     * @param int $next The character after the text.
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function angleOpens(string $value, int $i, int $next, Context $c): bool
    {
        $rest = substr($value, $i + 1);
        $xref = str_starts_with($rest, '/#');
        $length = strcspn($rest, $xref ? "> \n" : "> \t\n<");
        if ($length >= strlen($rest)) {
            $atEnd = $c->here >= 0 && $c->here + strlen($value) >= strlen((string)$c->flat);
            if ($atEnd && !$c->inBracket) {
                return false;
            }
            // The leaf of a bracket ends at its `]`, which a URL body can run through.
            $following = $atEnd ? ']' : self::ahead($value, strlen($value), 1, $c, $next);
            if ($following !== '>') {
                return $following === '' || self::bodyStarts($rest . $following);
            }
        } elseif ($rest[$length] !== '>') {
            return false;
        }
        $body = substr($rest, 0, $length);
        if ($xref) {
            return strlen($body) > 2;
        }

        return Scanner::isUrl($body) || Scanner::isEmail($body);
    }

    /**
     * Whether `$head` can start the body of an autolink or a cross-reference.
     *
     * @param string $head
     *
     * @return bool
     */
    private static function bodyStarts(string $head): bool
    {
        $n = strlen($head);
        if ($head[0] === '/') {
            return $n === 1 || $head[1] === '#';
        }
        $local = strspn($head, Tables::AUTOLINK_EMAIL_CHARS);
        if ($local === $n || ($head[$local] === '@' && strspn($head, Tables::AUTOLINK_EMAIL_CHARS, $local + 1) === $n - $local - 1)) {
            return true;
        }
        if (strspn($head[0], Tables::AUTOLINK_SCHEME_FIRST) === 0) {
            return false;
        }
        $colon = 1 + strspn($head, Tables::AUTOLINK_SCHEME_REST, 1);
        if ($colon === $n) {
            return true;
        }
        if ($head[$colon] !== ':') {
            return false;
        }
        $end = $colon + 1 + strspn($head, Tables::AUTOLINK_URL_CHARS, $colon + 1);

        return $end === $n || ord($head[$end]) >= 0x80;
    }

    /**
     * C2-W-093, C2-I-047, C2-I-048: a lone `/` or `*` that can open or close, where the leaf holds another one.
     *
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param int $after
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function pairs(string $value, int $i, int $prev, int $after, Context $c): bool
    {
        $ch = $value[$i];
        if (($c->counts[$ch] ?? 0) < 2) {
            return false;
        }
        if (self::loneBehindBrace($value, $i, $ch, $c)) {
            return true;
        }
        if (self::emptyBracedCloser($value, $i, $c)) {
            return false;
        }
        // C2-I-049: a run of two or more is text, counted without the mark a bare brace takes (C2-I-055).
        $run = self::run($value, $i, $c);
        if ($run === null || $run[1] > $run[0]) {
            return false;
        }
        if ($after >= 0 && Chars::isGlue($after)) {
            return false;
        }
        $open = !Chars::isWord($prev) && !Chars::isSpace($after);
        $close = !Chars::isSpace($prev) && !Chars::isWord($after);
        if ($c->here < 0) {
            return ($open || $close) && ($c->localPartners[$ch][$i] ?? false);
        }
        $at = $c->here + $i;
        $c->partners ??= self::flatPartners($c);
        $behind = $at + 1;
        if (
            $open && !isset($c->partners[$ch][$at]) && $i === strlen($value) - 1 && ($c->flat[$behind] ?? '') === "\0"
            && !in_array($c->flatTypes[$behind] ?? '', ['link', 'image', 'span', 'inline_footnote', 'inline_extension'], true)
            && (str_contains($c->flatAtoms[$behind] ?? '', $ch) || self::holdsPairable($c->flatNodes[$behind] ?? [], $ch))
        ) {
            return true;
        }

        return ($open || $close) && self::partner($c, $at, $ch);
    }

    /**
     * C2-I-055: in the text of a span, a lone mark behind the mark a bare brace takes pairs only with a later one of its
     * kind: one in the text that no brace takes, the span's own closer, or one in a node behind the text.
     */
    private static function loneBehindBrace(string $value, int $i, string $ch, Context $c): bool
    {
        if ($i < 2 || $c->here >= 0 || $value[$i - 1] !== $ch || $value[$i - 2] !== '{' || ($value[$i + 1] ?? '') === $ch) {
            return false;
        }
        // C2-I-043: in `{//}` the second mark closes the bare brace's opener, which holds nothing, and pairs with nothing.
        if (($value[$i + 1] ?? '') === '}') {
            return false;
        }
        $span = $c->spanTexts[$c->textPath] ?? null;

        return $span === null || $span[0] === $ch || $span[1] || self::bareMarkBehind($value, $i + 1, $ch);
    }

    /**
     * C2-I-043: the second mark of `{//}` behind a brace left bare closes that opener, which holds nothing, and is no
     * delimiter.
     */
    private static function emptyBracedCloser(string $value, int $i, Context $c): bool
    {
        return $i >= 2 && ($value[$i + 1] ?? '') === '}' && $value[$i - 1] === $value[$i] && $value[$i - 2] === '{'
            && ($c->escapedAt[$i - 2] ?? true) === false;
    }

    /**
     * C2-I-043, C2-I-055: the mark of `{/}` behind a brace left bare belongs to that braced opener, so it closes nothing.
     */
    private static function bracedOpenerMark(string $value, int $i, Context $c): bool
    {
        return $i >= 1 && $value[$i - 1] === '{' && ($c->escapedAt[$i - 1] ?? true) === false;
    }

    /**
     * Whether `$value` holds a `$mark` from `$from` on that is no braced opener's mark (C2-I-055).
     */
    private static function bareMarkBehind(string $value, int $from, string $mark): bool
    {
        for ($at = strpos($value, $mark, $from); $at !== false; $at = strpos($value, $mark, $at + 1)) {
            if ($value[$at - 1] !== '{') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$node` holds a `$mark` that can open or close: C2-I-047, one between two word characters cannot.
     *
     * @param array<mixed> $node
     * @param string $mark
     *
     * @return bool
     */
    private static function holdsPairable(array $node, string $mark): bool
    {
        [$closes, , $blocked] = self::heldMarks($node, $mark);

        return $closes && !$blocked;
    }

    /**
     * Whether the texts of `$node` hold a bare `$mark` that can close a pair opened before it, and one between two word
     * characters (C2-I-047), and whether such a one comes before the first closer, so that the span the closer would
     * end does not form (C2-I-053). The content stands between the marks of its node, and a mark glued before a closing
     * mark of its own kind makes a run, which is text (C2-I-049). A bracketed node keeps its marks to itself.
     *
     * @param array<mixed> $node
     * @param string $mark
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private static function heldMarks(array $node, string $mark): array
    {
        $closes = false;
        $between = false;
        $blocked = false;
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $edge = ['strong' => 0x2A, 'emphasis' => 0x2F][$type] ?? 0x7D;
        $nodes = is_array($node['children'] ?? null) ? $node['children'] : [];
        $last = array_key_last($nodes);
        foreach ($nodes as $k => $child) {
            if (!is_array($child)) {
                continue;
            }
            $type = is_string($child['type'] ?? null) ? $child['type'] : '';
            if ($type === 'text' && is_string($child['value'] ?? null)) {
                $behind = $k === $last ? $edge : 0x7D;
                self::textMarks($child['value'], $mark, $edge, $behind, $closes, $between, $blocked, $k > array_key_first($nodes));
            } elseif (
                !isset(self::WHOLE[$type]) && !in_array($type, ['link', 'image', 'span', 'inline_footnote', 'inline_extension'], true)
                && is_array($child['children'] ?? null)
            ) {
                [$held, $inside, $first] = self::heldMarks($child, $mark);
                $blocked = $blocked || (!$closes && $first);
                $closes = $closes || $held;
                $between = $between || $inside;
            }
        }

        return [$closes, $between, $blocked];
    }

    /**
     * The marks of one text for `heldMarks()`, between the characters `$edge` before it and `$behind` after it.
     *
     * @param string $value
     * @param string $mark
     * @param int $edge
     * @param int $behind
     * @param bool $closes
     * @param bool $between
     * @param bool $blocked
     * @param bool $afterNode
     */
    private static function textMarks(
        string $value,
        string $mark,
        int $edge,
        int $behind,
        bool &$closes,
        bool &$between,
        bool &$blocked,
        bool $afterNode = false,
    ): void {
        $n = strlen($value);
        for ($at = strpos($value, $mark); $at !== false; $at = strpos($value, $mark, $at + 1)) {
            $run = strspn($value, $mark, $at);
            if ($run > 1 || ($at + 1 === $n && $behind === ord($mark))) {
                $at += $run - 1;

                continue;
            }
            // C2-I-060: the mark of a bare brace with no closer behind it is that opener's, and pairs with nothing. With
            // a node behind the text, the brace may take a backslash, which frees its mark.
            $alone = $behind !== 0x7D && ($at > 1 || !$afterNode);
            if ($alone && $at > 0 && $value[$at - 1] === '{' && !self::bracedCloserPairs(substr($value, $at + 2), $mark)) {
                continue;
            }
            $before = $at > 0 ? Chars::before($value, $at) : $edge;
            $after = $at + 1 < $n ? Chars::at($value, $at + 1) : $behind;
            if (Chars::isWord($before) && Chars::isWord($after)) {
                $blocked = $blocked || !$closes;
                $between = true;
            } elseif (!Chars::isSpace($before) && !Chars::isWord($after)) {
                $closes = true;
            }
        }
    }

    /**
     * Whether `$tail`, the text behind a braced opener of `$mark`, holds a braced closer of that mark. An empty pair
     * `{//}` in it holds its own closer and forms nothing (C2-I-043, C2-I-057), so its `/}` is no closer for the
     * opener, and the mark of a later braced opener is no closer either (C2-I-055).
     */
    public static function bracedCloserPairs(string $tail, string $mark): bool
    {
        $n = strlen($tail);
        for ($i = 0; $i < $n; $i++) {
            if ($tail[$i] === '{' && ($tail[$i + 1] ?? '') === $mark) {
                $i += substr($tail, $i + 2, 2) === $mark . '}' ? 3 : 1;

                continue;
            }
            if ($tail[$i] === $mark && ($tail[$i + 1] ?? '') === '}') {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-047: an opener pairs only with a mark after it that can close, and a closer only with one before it that
     * can open. A node that is not read whole may hold one. The first opener and the last closer of each mark in the
     * leaf are found once.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $at
     * @param string $ch
     *
     * @return bool
     */
    private static function partner(Context $c, int $at, string $ch): bool
    {
        $c->partners ??= self::flatPartners($c);
        $paired = $c->partners[$ch][$at] ?? null;

        return $paired === true || ($paired === false && self::undecided());
    }

    /**
     * The pairs of the leaf's own text. C2-I-055, C2-I-058: a mark glued behind a `{` that stays bare is the mark of a
     * braced opener and pairs with no bare mark; the brace takes the backslash only where a closer of that mark follows.
     *
     * @return array<string, array<int, bool>>
     */
    private static function flatPartners(Context $c): array
    {
        $flat = (string)$c->flat;

        return self::pairedMarks(
            $flat,
            $c->flatTypes,
            $c->flatNodes,
            static fn (int $at): bool => !self::closerFollows($flat, $at, $flat[$at], $c),
        );
    }

    /**
     * Pair bare marks with the same flanking and nearest-opener rule as the reader. Only a bracketed node bounds the
     * delimiter stack (C2-I-007): a text bracket either forms nothing or takes a backslash. An opener a node takes
     * across a text bracket is marked false: the reader may pair the bracket's content first, so the read-back decides.
     *
     * @param string $text
     * @param array<int, string> $types
     * @param array<int, array<mixed>> $nodes
     * @param \Closure(int): bool|null $braced Whether the mark at an offset behind a `{` is the mark of a braced opener.
     * @param string $behind The character written behind the text, read for flanking and runs only.
     *
     * @return array<string, array<int, bool>>
     */
    private static function pairedMarks(
        string $text,
        array $types = [],
        array $nodes = [],
        ?Closure $braced = null,
        string $behind = '',
    ): array {
        $paired = ['*' => [], '/' => []];
        $open = ['*' => [], '/' => []];
        $bracedOpen = ['*' => [], '/' => []];
        $inner = ['*' => -1, '/' => -1];
        $bracket = -1;
        $n = strlen($text);
        $text .= $behind;
        for ($at = 0; $at < $n; $at++) {
            $ch = $text[$at];
            if ($ch === "\0") {
                $before = Chars::before($text, $at);
                self::pairNode($types[$at] ?? '', $nodes[$at] ?? [], $open, $paired, $inner, $at, $bracket, $before);

                continue;
            }
            if ($ch === '[' || $ch === ']') {
                $bracket = $at;

                continue;
            }
            if ($ch !== '*' && $ch !== '/') {
                continue;
            }
            // C2-I-047, C2-I-048: the flanking tests read code points, not the bytes of a UTF-8 sequence.
            $before = Chars::before($text, $at);
            $after = Chars::at($text, $at + 1);
            // C2-I-049: the mark a bare brace takes as a braced opener does not count for the run behind it.
            $behindBraced = $braced !== null && $at > 1 && $text[$at - 1] === $ch && $text[$at - 2] === '{' && $braced($at - 1);
            if (
                self::bracedToken($at, $before, $after, $braced, $bracedOpen[$ch], $open)
                || ($at > 0 && $text[$at - 1] === $ch && !$behindBraced) || ($text[$at + 1] ?? '') === $ch
                || ($after >= 0 && Chars::isGlue($after))
            ) {
                continue;
            }
            $canOpen = !Chars::isWord($before) && !Chars::isSpace($after);
            $canClose = !Chars::isSpace($before) && !Chars::isWord($after);
            if (Chars::isWord($before) && Chars::isWord($after)) {
                $inner[$ch] = $at;
            } elseif ($canClose && $open[$ch] !== []) {
                $from = array_pop($open[$ch]);
                // C2-I-053: a span that holds its own mark between two word characters does not form.
                if ($inner[$ch] < $from) {
                    $paired[$ch][$from] = true;
                }
            } elseif ($canOpen) {
                $open[$ch][] = $at;
            }
        }

        return $paired;
    }

    /**
     * C2-I-057, C2-I-059: before `}` with a braced opener of its mark open, a mark is that opener's closer, and the
     * bare entries opened after the opener are text. Whether the mark at `$at` is a braced token: that closer, or the
     * mark of a braced opener, which it records.
     *
     * @param int $at
     * @param int $before
     * @param int $after
     * @param \Closure(int): bool|null $braced
     * @param array<int> $bracedOpen The open braced openers of the mark.
     * @param array<string, array<int>> $open
     *
     * @return bool
     */
    private static function bracedToken(int $at, int $before, int $after, ?Closure $braced, array &$bracedOpen, array &$open): bool
    {
        if ($braced === null) {
            return false;
        }
        if ($after === 0x7D && $bracedOpen !== []) {
            $from = array_pop($bracedOpen);
            foreach (['*', '/'] as $mark) {
                while ($open[$mark] !== [] && end($open[$mark]) > $from) {
                    array_pop($open[$mark]);
                }
            }

            return true;
        }
        if ($before === 0x7B && $braced($at)) {
            $bracedOpen[] = $at;

            return true;
        }

        return false;
    }

    /**
     * @param string $type
     * @param array<mixed> $node
     * @param array<string, array<int>> $open
     * @param array<string, array<int, bool>> $paired
     * @param array<string, int> $inner The last offset of each mark between two word characters.
     * @param int $at The offset of the node.
     * @param int $bracket The offset of the last text bracket.
     * @param int $before The byte before the node.
     */
    private static function pairNode(
        string $type,
        array $node,
        array &$open,
        array &$paired,
        array &$inner,
        int $at,
        int $bracket,
        int $before,
    ): void {
        // A bracketed node bounds the delimiter stack. Its marks cannot close
        // a pair opened in text outside its bracketed spelling.
        if (isset(self::WHOLE[$type]) || in_array($type, ['link', 'image', 'span', 'inline_footnote', 'inline_extension'], true)) {
            return;
        }
        $closes = !self::opensOnly($node, $before);
        foreach (['*', '/'] as $mark) {
            // C2-I-047: a held mark between two word characters closes nothing; C2-I-053: it keeps an outer span of
            // its mark from forming.
            [$held, $between, $blocked] = self::heldMarks($node, $mark);
            if ($between) {
                $inner[$mark] = $at;
            }
            if (
                $open[$mark] !== [] && ($held && ($before !== ord($mark) || self::braceHolds($node, $mark))
                || ($closes && $before !== ord($mark)
                && (($mark === '*' && $type === 'strong') || ($mark === '/' && $type === 'emphasis'))))
            ) {
                $from = array_pop($open[$mark]);
                $paired[$mark][$from] = $from > $bracket && !($held && $blocked);
            }
        }
    }

    /**
     * A span behind its own mark is braced: only a mark of its content that can open or close reaches a pair outside.
     *
     * @param array<mixed> $node
     * @param string $mark
     *
     * @return bool
     */
    private static function braceHolds(array $node, string $mark): bool
    {
        $type = $node['type'] ?? '';
        if (($type !== 'emphasis' || $mark !== '/') && ($type !== 'strong' || $mark !== '*')) {
            return true;
        }

        return Inline::holdsMark(is_array($node['children'] ?? null) ? $node['children'] : [], $mark, false);
    }

    /**
     * C2-I-047: the opening mark of a span after a space, or before a word character, cannot close a pair. A span that
     * takes its braced form whatever its escapes (C2-W-069, C2-W-070) has a braced opener, which closes nothing either.
     *
     * @param array<mixed> $node
     * @param int $before
     */
    private static function opensOnly(array $node, int $before): bool
    {
        if (Chars::isSpace($before) || Inline::bracedAlways($node, $before)) {
            return true;
        }
        $first = is_array($node['children'] ?? null) ? $node['children'][0] ?? null : null;
        $value = is_array($first) && ($first['type'] ?? null) === 'text' && is_string($first['value'] ?? null) ? $first['value'] : '';

        return $value !== '' && Chars::isWord(Chars::at($value, 0));
    }

    /**
     * The run of `$value[$i]` without the mark that a bare brace before it takes as a braced opener; null
     * when `$i` is that mark.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: int, 1: int}|null
     */
    private static function run(string $value, int $i, Context $c): ?array
    {
        [$start, $end] = self::bounds($value, $i);
        if ($start > 0 && $value[$start - 1] === '{' && ($c->escapedAt[$start - 1] ?? true) === false) {
            if ($i === $start) {
                return null;
            }
            $start++;
        }

        return [$start, $end];
    }

    /**
     * The first and last offset of the run of `$value[$i]` around `$i`. Every mark of a run asks for its run, so the
     * bounds of the last run are kept: measured again for each mark, a run of n marks cost n squared.
     *
     * @param string $value
     * @param int $i
     *
     * @return array{0: int, 1: int}
     */
    private static function bounds(string $value, int $i): array
    {
        static $last = ['', -1, -1];
        if ($i >= $last[1] && $i <= $last[2] && $value === $last[0]) {
            return [$last[1], $last[2]];
        }
        $ch = $value[$i];
        $start = $i;
        while ($start > 0 && $value[$start - 1] === $ch) {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === $ch) {
            $end++;
        }
        $last = [$value, $start, $end];

        return [$start, $end];
    }

    /**
     * C2-W-099: in a run of two or more hyphens every second one takes a backslash, starting with the second; a run
     * glued to a dash of the tree before it counts that dash's hyphens (C2-W-125 case four), and a run glued to one
     * after it ends with an escaped hyphen.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function hyphen(string $value, int $i, Context $c, int $next): bool
    {
        [$start, $end] = self::bounds($value, $i);
        $n = strlen($value);
        $before = $c->hyphensBefore > 0 ? $c->hyphensBefore : ($c->last === 0x2D ? 1 : 0);
        $offset = $i - $start + ($start === 0 ? $before : 0);
        $glued = $end === $n - 1 && $next === 0x2D && !$c->hyphenAfter;
        if ($end === $start && $offset === 0 && !$glued) {
            return false;
        }
        // A run glued to the hyphen of the parent's mark, `{-` or `-}`, may not join it; the read-back decides.
        if (($start === 0 && $c->hyphensBefore === 0 && $c->last === 0x2D) || ($glued && $c->lastInContent)) {
            return self::undecided();
        }

        return $offset % 2 === 1 || ($glued && $i === $end);
    }

    /**
     * C2-I-114: three bare dots read as an ellipsis. Short runs keep the writer's simple spelling; long runs use the
     * compact spelling of one escape per three dots. The tail also needs escapes until at most two bare dots remain.
     *
     * A run goes on through the text and dot atoms around the value.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function dot(string $value, int $i, Context $c): bool
    {
        [$start, $end] = self::bounds($value, $i);
        $before = $start === 0 ? $c->dotsBefore : 0;
        $after = $end === strlen($value) - 1 ? $c->dotsAfter : 0;
        $length = $end - $start + 1 + $before + $after;
        $offset = $i - $start + $before;
        if ($length < 15) {
            return $end - $i + $after >= 2;
        }
        $last = intdiv($length - 3, 3) * 3;

        return $offset <= $length - 3 && ($offset % 3 === 0 || $offset > $last);
    }

    /**
     * C2-W-088: a symbol or an extension name may run on into the next nodes of the leaf.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function colonOpens(string $value, int $i, Context $c, int $next): bool
    {
        // A character inside the text that starts neither a symbol name nor an extension name opens nothing.
        $first = $value[$i + 1] ?? '';
        if ($first !== '' && strspn($first, self::NAME_STARTS) === 0) {
            return false;
        }
        $len = strspn($value, Tables::SIGIL_SYMBOL_REST, $i + 1);
        if ($i + 1 + $len === strlen($value)) {
            [$tail, $next] = self::nameOn($value, $c, $next);
            $value .= $tail;
        }

        return self::symbolFollows($value, $i) || self::extensionFollows($value, $i, $next);
    }

    /**
     * The leaf behind the text, spelled as written, up to and with the first character no symbol name holds, and the
     * first character after that; at a node that is no atom, nothing more and that node's first character.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return array{0: string, 1: int}
     */
    private static function nameOn(string $value, Context $c, int $next): array
    {
        if ($c->here < 0) {
            return ['', $next];
        }
        $flat = (string)$c->flat;
        $n = strlen($flat);
        $out = '';
        for ($at = $c->here + strlen($value); $at < $n; $at++) {
            if ($flat[$at] !== "\0") {
                $out .= $flat[$at];
                if (!str_contains(Tables::SIGIL_SYMBOL_REST, $flat[$at])) {
                    return [$out, $at + 1 < $n && $flat[$at + 1] !== "\0" ? ord($flat[$at + 1]) : -1];
                }

                continue;
            }
            if (!isset($c->flatAtoms[$at])) {
                return [$out, $c->flatFirst[$at] ?? -1];
            }
            $out .= $c->flatAtoms[$at];
            if (strspn($c->flatAtoms[$at], Tables::SIGIL_SYMBOL_REST) < strlen($c->flatAtoms[$at])) {
                return [$out, -1];
            }
        }

        return [$out, $next];
    }

    /**
     * The `$length` characters from `$from`, read on into the leaf behind the text: its text and the spelling of its
     * atoms, then the first character of a node that is no atom.
     *
     * @param string $value
     * @param int $from
     * @param int $length
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private static function ahead(string $value, int $from, int $length, Context $c, int $next): string
    {
        $out = substr($value, $from, $length);
        if (strlen($out) === $length) {
            return $out;
        }
        if ($c->here < 0) {
            return $out . ($next >= 0 && $next < 0x80 ? chr($next) : '');
        }
        $flat = (string)$c->flat;
        $n = strlen($flat);
        for ($at = $c->here + strlen($value); $at < $n; $at++) {
            if (strlen($out) >= $length) {
                break;
            }
            if ($flat[$at] !== "\0") {
                $out .= $flat[$at];
            } elseif (isset($c->flatAtoms[$at])) {
                $out .= $c->flatAtoms[$at];
            } else {
                $first = $c->flatFirst[$at] ?? -1;

                return $out . ($first >= 0 && $first < 0x80 ? chr($first) : '');
            }
        }

        return substr($out, 0, $length);
    }

    /**
     * C2-W-095: a text after an atom whose spelling, with the start of the text, spells a longer replaced run (`--`
     * and `>`, `<=` and `>`). The atom reads first, so a run that starts inside its spelling does not form.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function atomRunGoesOn(string $value, Context $c, int $next): bool
    {
        if ($c->here < 1 || !isset($c->flatAtoms[$c->here - 1]) || !str_contains(self::RUN_CHARACTERS, $value[0])) {
            return false;
        }
        $spelled = $c->flatAtoms[$c->here - 1];
        $text = self::ahead($value, 0, 3, $c, $next);
        $n = strlen($spelled);
        foreach (Tables::SMART_RUNS_BY_FIRST[$spelled[0] ?? ''] ?? [] as $run) {
            if (strlen($run) > $n && str_starts_with($run, $spelled) && str_starts_with($text, substr($run, $n))) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-093: `:`, a symbol name and `:`.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function symbolFollows(string $value, int $i): bool
    {
        if (strspn($value, Tables::SIGIL_SYMBOL_FIRST, $i + 1, 1) !== 1) {
            return false;
        }
        $close = $i + 2 + strspn($value, Tables::SIGIL_SYMBOL_REST, $i + 2);

        return ($value[$close] ?? '') === ':' && $value[$close - 1] !== '-';
    }

    /**
     * C2-I-094, C2-W-095: `:`, an inline extension name and `[`, which may be the first character of the next node.
     *
     * @param string $value
     * @param int $i
     * @param int $next
     *
     * @return bool
     */
    private static function extensionFollows(string $value, int $i, int $next): bool
    {
        if (strspn($value, Tables::SIGIL_INLINE_EXTENSION_FIRST, $i + 1, 1) !== 1) {
            return false;
        }
        $len = 1 + strspn($value, Tables::SIGIL_INLINE_EXTENSION_REST, $i + 2);

        $end = $i + 1 + $len;

        return $end < strlen($value) ? $value[$end] === '[' : $next === 0x5B;
    }

    private static function sigilName(string $value, int $i, Context $c, int $next): bool
    {
        if (strspn($value, self::NAME, $i + 1) < strlen($value) - $i - 1) {
            return self::nameFollows($value, $i, $next);
        }
        [$tail, $after] = self::runOn($value, $c, $next);

        return self::nameFollows($value . $tail, $i, $after);
    }

    /**
     * C2-I-092: a letter, number or mark outside ASCII behind the run of a name makes its sigil start no form.
     *
     * @param int $cp
     *
     * @return bool
     */
    public static function endsNoName(int $cp): bool
    {
        return $cp >= 0x80 && (Chars::isWord($cp) || Chars::inRanges($cp, Tables::UNICODE_MARK_RANGES));
    }

    private static function isLetterOrNumber(int $cp): bool
    {
        return $cp >= 0 && Chars::isWord($cp);
    }

    /**
     * C2-I-092: what a name at the end of the text reads on into, where the leaf is known: the spelling of each atom
     * behind the text and the text behind that atom, up to the first character that ends the name; and the first
     * character after that, the first character of a node that is no atom, or -1 at the end of the leaf.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next The first character after the text.
     *
     * @return array{0: string, 1: int}
     */
    private static function runOn(string $value, Context $c, int $next): array
    {
        $flat = (string)$c->flat;
        $at = $c->here + strlen($value);
        if ($c->here < 0 || !isset($c->flatAtoms[$at])) {
            return ['', $next];
        }
        $out = '';
        while (isset($c->flatAtoms[$at])) {
            $node = strpos($flat, "\0", $at + 1);
            $stop = $node === false ? strlen($flat) : $node;
            $text = substr($flat, $at + 1, $stop - $at - 1);
            $out .= $c->flatAtoms[$at] . $text;
            if ($node === false) {
                return [$out, -1];
            }
            if (strspn($text, self::NAME) < strlen($text) || strspn($c->flatAtoms[$at], self::NAME) < strlen($c->flatAtoms[$at])) {
                return [$out, -1];
            }
            $at = $node;
        }

        return [$out, $c->flatFirst[$at] ?? -1];
    }

    /**
     * C2-I-092: runs of letters, digits, `_` and `-` joined by single dots, holding a letter. A name that runs on into
     * a node that is no atom counts as one, since that node may continue it.
     *
     * @param string $value The text, followed by the spelling it reads on into.
     * @param int $i
     * @param int $next The first character after `$value`.
     *
     * @return bool
     */
    private static function nameFollows(string $value, int $i, int $next): bool
    {
        // A dot only joins two runs, so no name starts with one.
        if (($value[$i + 1] ?? '') === '.' || ($i + 1 === strlen($value) && $next === 0x2E)) {
            return false;
        }
        $len = strspn($value, self::NAME, $i + 1);
        $end = $i + 1 + $len;
        if ($len > 0 && self::endsNoName($end < strlen($value) ? Chars::at($value, $end) : $next)) {
            return false;
        }
        $runsOn = $end === strlen($value) && $next >= 0 && $next < 0x80 && str_contains(self::NAME, chr($next));
        if ($len === 0) {
            return $runsOn;
        }
        $run = substr($value, $i + 1, $len);
        $double = strpos($run, '..');
        $name = $double === false ? $run : substr($run, 0, $double);

        return preg_match('/[a-zA-Z]/', $name) === 1 || ($runsOn && $double === false);
    }

    /**
     * C2-W-016: whether an emitted line of a verbatim leaf opens a block in its paragraph owner. The first-line option
     * also checks a span whose opening run starts a block at the paragraph's column.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Writer\Context|null $context
     * @param bool $first
     *
     * @return bool
     */
    public static function opensBlock(string $line, ?Context $context = null, bool $first = false): bool
    {
        $line = ltrim($line, " \t");
        if ($line === '' || strspn($line, '`') >= 3) {
            return true;
        }
        if ($context !== null && str_starts_with($line, '%%')) {
            return true;
        }
        if ($context === null) {
            $c = new Context();
            $c->lineStart = true;
            $c->last = 10;
            $c->owner = 'body';

            return self::lineStartMark($line . ' ', $c, 0) >= 0;
        }
        $c = clone $context;
        $c->lineStart = true;
        $c->last = $first ? -1 : 10;

        return self::lineStartMark($line, $c, 10) >= 0;
    }

    /**
     * C2-W-092: the offset of the character that keeps a line of text from opening a block, or -1. The first line of a
     * paragraph is tested for every block opener, by the parse of that line; a later line only for the interrupters
     * its owner admits.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return int
     */
    private static function lineStartMark(string $value, Context $c, int $next): int
    {
        $c->behindMarker = [];
        if (!self::canOpenBlockAtLineStart($value, $c)) {
            return -1;
        }
        $tail = $c->lineTail ?? (in_array($next, [-1, 10], true) ? null : self::restOfLine($value, $c));
        // A hard break ends the line with its backslash (C2-B-166, C2-B-167: such a line is no row).
        if ($tail === null && $next === 92 && ($c->flat[$c->here + strlen($value)] ?? '') === "\n") {
            $tail = '\\';
        }
        $eol = $tail !== null || in_array($next, [-1, 10], true);
        $line = $tail !== null ? $value . $tail : ($eol ? $value : $value . 'x');
        $value = $tail !== null ? $line : $value;
        // C2-B-190: a fence-shaped line inside a quote at the block cap
        // cannot open another block and therefore remains paragraph text.
        if (
            $c->blockDepth >= Tables::BLOCK_DEPTH
            && $c->owner === 'quote'
            && !$c->hasFencedAncestor
            && $line[0] === ':'
            && LineShapes::isFenceLine($line, 0, strlen($line))
        ) {
            return -1;
        }
        // C2-B-125: past the block cap a lone `+` line can attach to a colon line the cap left as text.
        if ($c->blockDepth >= Tables::BLOCK_DEPTH && !$c->inQuote && rtrim($line, " \t") === '+') {
            return 0;
        }
        if (self::anyLine($value, $line, $eol, $c->last === -1, $next === 10)) {
            $c->openedLine = $line;
            $c->behindMarker = self::markerLineOpeners($line, $c);

            return 0;
        }
        // C2-B-027: a code fence behind the leading blanks of a line opens too; its first backtick takes the backslash.
        $lead = strspn($line, " \t");
        if ($lead > 0 && ($line[$lead] ?? '') === '`' && LineShapes::isFenceLine($line, $lead, strlen($line))) {
            $c->openedLine = $line;

            return $lead;
        }

        $mark = self::lineMark($line, $eol, $c);
        if ($mark >= 0 && $mark < strlen($line) - 1) {
            $c->behindMarker = self::nestedOpeners($line, $mark, $c);
        }

        return $mark;
    }

    private static function canOpenBlockAtLineStart(string $value, Context $c): bool
    {
        // A line block has no paragraph block openers. Only a comment fence can
        // still claim its following lines inside the block.
        return $c->lineStart && (!$c->inLineBlock || str_starts_with($value, '%%%'));
    }

    /**
     * A line of marks such as `* * *` is a list or quote marker before another block opener, which takes its own
     * escape (C2-W-088).
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<int, true>
     */
    private static function markerLineOpeners(string $line, Context $c): array
    {
        return strlen($line) > 1 && str_contains('-*+>', $line[0]) ? self::nestedOpeners($line, 0, $c) : [];
    }

    /**
     * C2-W-093: the marks of a text inside a node that pair, read across the content that holds the text, so a mark
     * of a sibling node can take its partner.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<string, array<int, bool>>
     */
    private static function siblingPartners(string $value, Context $c): array
    {
        $at = $c->siblings['at'][$c->textPath] ?? null;
        if ($c->siblings === null || $at === null || substr_compare($c->siblings['flat'], $value, $at, strlen($value)) !== 0) {
            return self::pairedMarks($value);
        }
        // C2-I-049: the closing mark of a span stands behind its content, and a mark glued before it makes a run.
        $c->siblings['partners'] ??= self::pairedMarks(
            $c->siblings['flat'],
            $c->siblings['types'],
            $c->siblings['nodes'],
            null,
            in_array($c->siblings['next'], [0x2A, 0x2F], true) ? chr($c->siblings['next']) : '',
        );
        $local = [];
        foreach ($c->siblings['partners'] as $mark => $offsets) {
            foreach ($offsets as $offset => $paired) {
                if ($offset >= $at && $offset < $at + strlen($value)) {
                    $local[$mark][$offset - $at] = $paired || self::undecided();
                }
            }
        }

        return $local;
    }

    /**
     * C2-W-088: the escapes of the block openers that the parse of a line that opens a block starts behind its first
     * opener, on the same line, at the character C2-W-090 escapes.
     *
     * @param string $line
     * @param int $mark The offset the line's own opener takes.
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array<int, true>
     */
    private static function nestedOpeners(string $line, int $mark, Context $c): array
    {
        $c->writerState?->chargeRepair($c->textPath, 'nested-opener', $line . "\n");
        $starts = [];
        self::blockStarts(Carve::parse($line . "\n"), strlen($line), $starts);
        $own = [];
        foreach (array_keys($starts) as $at) {
            if ($at <= $mark) {
                continue;
            }
            if (ctype_alnum($line[$at])) {
                $at += strcspn($line, self::PUNCT, $at);
            } elseif (substr($line, $at, 2) === '--') {
                $at++;
            }
            if ($at < strlen($line) && self::isPunct($line[$at])) {
                $own[$at] = true;
            }
        }

        return $own;
    }

    /**
     * The offsets on the first line where a block of `$node` other than a paragraph starts.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $length
     * @param array<int, true> $starts
     *
     * @return void
     */
    private static function blockStarts(Node $node, int $length, array &$starts): void
    {
        foreach ($node->children ?? [] as $child) {
            if ($child->start < 0 || $child->start >= $length) {
                continue;
            }
            if ($child->type !== 'paragraph') {
                $starts[$child->start] = true;
            }
            if (!isset(DataModel::INLINE_PARENTS[$child->type])) {
                self::blockStarts($child, $length, $starts);
            }
        }
    }

    /**
     * The rest of the line behind the text as the bare write spells it, so the line test reads the whole line
     * (C2-B-118, C2-B-166): a verbatim node by its spelling, a span by its mark and text, another node by its first
     * character where its strings hold no backtick; no closing mark is a pipe. Null where the leaf does not tell.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string|null
     */
    private static function restOfLine(string $value, Context $c): ?string
    {
        if ($c->flat === null || $c->here < 0) {
            return null;
        }
        $flat = $c->flat;
        $n = strlen($flat);
        $rest = '';
        for ($i = $c->here + strlen($value); $i < $n && $flat[$i] !== "\n"; $i++) {
            $spelled = $flat[$i] !== "\0" ? $flat[$i] : $c->flatAtoms[$i] ?? self::nodeOnLine($i, $c);
            if ($spelled === null) {
                return null;
            }
            $break = strpos($spelled, "\n");
            if ($break !== false) {
                return $rest . substr($spelled, 0, $break);
            }
            $rest .= $spelled;
        }

        return $rest === '' ? null : $rest;
    }

    /**
     * The spelling of the node at offset `$i` of the flat leaf as far as the line test needs it, a line feed where the
     * line ends inside it; null where it does not tell.
     *
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string|null
     */
    private static function nodeOnLine(int $i, Context $c): ?string
    {
        $node = $c->flatNodes[$i] ?? null;
        if ($node === null) {
            return null;
        }
        $spelled = self::nodeSpelling($node, $c->flatTypes[$i] ?? '', $c->flatFirst[$i] ?? -1);

        // The brace block follows the node's spelling on its line.
        return $spelled === null || !isset($node['attrs']) ? $spelled : $spelled . BraceBlock::of($node);
    }

    /**
     * The spelling of a node on its line without its brace block, as `nodeOnLine()` reads it.
     *
     * @param array<mixed> $node
     * @param string $type
     * @param int $first
     *
     * @return string|null
     */
    private static function nodeSpelling(array $node, string $type, int $first): ?string
    {
        if (in_array($type, self::TICK_ATOMS, true)) {
            return self::verbatimSpelling($node, $type);
        }
        if ($first <= 0 || $first >= 0x80) {
            return null;
        }
        // C2-B-166: a line comment ends its line, and a row line keeps it as the row's comment.
        if ($type === 'comment' && ($node['form'] ?? '') === 'line') {
            // As Inline::comment() writes it: trailing blanks dropped, line feeds as spaces.
            $value = is_string($node['value'] ?? null) ? str_replace("\n", ' ', rtrim($node['value'], " \t")) : '';

            return ' %%' . ($value !== '' ? ' ' . $value : '') . "\n";
        }
        if (Inline::spanMark($type) !== null) {
            $inner = self::spanText(array_diff_key($node, ['attrs' => true]));
            if ($inner !== null) {
                return chr($first) . $inner . 'x';
            }
        }
        // A backtick the node spells before any break stands on the line, which is all a backtick fence asks; a code
        // span inside spells its own.
        $tick = self::tickOnLine($node);
        if ($tick !== null) {
            return chr($first) . ($tick ? 'x`' : 'x');
        }

        return str_contains(Inline::strings($node), '`') ? null : chr($first) . 'x';
    }

    /**
     * Whether the node spells a backtick on its first line: in a text, a verbatim node, an alt, the key of a
     * reference, the destination of an inline link or an attribute value. Null where a break comes first.
     *
     * @param array<mixed> $node
     *
     * @return bool|null
     */
    private static function tickOnLine(array $node): ?bool
    {
        $type = $node['type'] ?? '';
        if ($type === 'soft_break' || $type === 'hard_break') {
            return null;
        }
        if (in_array($type, self::TICK_ATOMS, true)) {
            return true;
        }
        $found = self::tickInFields($node);
        if ($found !== false) {
            return $found;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $found = is_array($child) ? self::tickOnLine($child) : false;
            if ($found !== false) {
                return $found;
            }
        }

        return str_contains(Inline::strings(is_array($node['attrs'] ?? null) ? $node['attrs'] : []), '`') ? true : false;
    }

    /**
     * The `tickOnLine()` of the string fields a node spells before its content ends.
     *
     * @param array<mixed> $node
     *
     * @return bool|null
     */
    private static function tickInFields(array $node): ?bool
    {
        $target = isset($node['refForm']) ? $node['key'] ?? null : $node['href'] ?? null;
        foreach ([$node['value'] ?? null, $node['alt'] ?? null, $target] as $field) {
            $found = is_string($field) ? self::tickBeforeBreak($field) : false;
            if ($found !== false) {
                return $found;
            }
        }

        return false;
    }

    /**
     * @param string $value
     *
     * @return bool|null Whether a backtick comes before the first line feed; null where the line feed comes first.
     */
    private static function tickBeforeBreak(string $value): ?bool
    {
        $tick = strpos($value, '`');
        if ($tick === false) {
            return false;
        }
        $break = strpos($value, "\n");

        return $break !== false && $break < $tick ? null : true;
    }

    /**
     * The spelling of a code span, math or literal node; null for a raw node, whose format follows the span.
     *
     * @param array<mixed> $node
     * @param string $type
     *
     * @return string|null
     */
    private static function verbatimSpelling(array $node, string $type): ?string
    {
        $code = $node['value'] ?? null;
        if (!is_string($code) || $type === 'raw_inline') {
            return null;
        }

        return match ($type) {
            'math' => (($node['display'] ?? false) === true ? '$$' : '$'),
            'literal_inline' => '!',
            default => '',
        } . Inline::codeSpan($code);
    }

    /**
     * The text a paired or braced span holds, a break as a line feed and other markup as `x`; null where a verbatim
     * node or another node that holds a backtick stands in it.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function spanText(array $node): ?string
    {
        $flat = '';
        $owners = [];
        $shielded = [];
        if (!self::planNodes([$node], '', $flat, $owners, false, $shielded)) {
            return null;
        }

        return strtr(substr($flat, 1, -1), "\1", 'x');
    }

    /**
     * The openers of C2-W-092 that read on any line of a paragraph: a fence run, a star line, a `{{` line, a table row
     * and, on the first line of content that goes on, an attribute line.
     *
     * @param string $value
     * @param string $line
     * @param bool $eol
     * @param bool $first Whether the line is the first of its paragraph.
     * @param bool $attaches
     *
     * @return bool
     */
    private static function anyLine(string $value, string $line, bool $eol, bool $first, bool $attaches): bool
    {
        $len = strlen($line);

        return match ($value[0]) {
            ':', '%', '`' => LineShapes::isFenceLine($line, 0, $len),
            '*' => self::starLine($value),
            '{' => $eol && $first && self::braceLine($value, $attaches),
            '|' => !$eol || TableBuilder::split($line, 0, $len) !== null,
            default => false,
        };
    }

    /**
     * The first line of a paragraph opens a block where its parse alone is no single paragraph. C2-W-090: the backslash
     * stands before the first punctuation character of the opener, the delimiter of an ordered marker.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    private static function firstLine(string $line, Context $c): int
    {
        $ch = $line[0];
        // C2-B-046: behind the marker of an item, a box shape would read as its task box.
        if ($c->boxLead && preg_match('/^\[(.)\] [ \t]*[^ \t]/', $line, $m) === 1 && isset(Tables::TASK_MARKS[$m[1]])) {
            return 0;
        }
        if (!str_contains(self::LINE_OPENERS, $ch) && (!ctype_alnum($ch) || Markers::quickReject($line, 0, strlen($line)))) {
            return -1;
        }
        // C2-W-121
        if ($ch === ':' && $c->afterDescriptions && in_array($line[1] ?? '', [' ', "\t", ''], true)) {
            return 0;
        }
        $c->writerState?->chargeRepair($c->textPath, 'first-line', $line . "\n");
        [$count, $type] = self::blockRead($line . "\n");
        if ($count === 1 && $type === 'paragraph') {
            return -1;
        }
        if (self::hyphenRunStaysParagraph($line, $c)) {
            return 1;
        }
        // C2-B-081: a definition shape opens a definition only at column 0.
        if ($c->owner !== 'top' && isset(self::DEFINITIONS[$type])) {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * The mark of a line that `anyLine()` leaves open, as a first or a later line of its paragraph.
     *
     * @param string $line
     * @param bool $eol
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    private static function lineMark(string $line, bool $eol, Context $c): int
    {
        $mark = $c->last === -1 ? self::firstLine($line, $c) : self::laterLine($line, $eol, $c);
        if ($mark < 0 && $c->last !== -1 && self::opensBehind($c->prevOpenedLine, $line)) {
            $mark = self::firstLine($line, $c);
        }
        $c->openedLine = $mark >= 0 ? $line : null;

        return $mark;
    }

    /**
     * C2-W-088: the bare parse reads the line before as a block of its own, so a line behind it that the parse does not
     * take into that block can open a block too, and is then tested as a first line.
     *
     * @param string|null $before The line before, where it took a backslash as a block opener.
     * @param string $line
     *
     * @return bool
     */
    private static function opensBehind(?string $before, string $line): bool
    {
        if ($before === null) {
            return false;
        }
        $key = $before . "\n" . $line;
        if (!isset(self::$behindReads[$key])) {
            $both = Carve::parse($key . "\n")->children ?? [];
            $last = $both[count($both) - 1] ?? null;
            if (count(self::$behindReads) >= 256) {
                self::$behindReads = [];
            }
            self::$behindReads[$key] = $last !== null && $last->type !== 'paragraph'
                && count($both) > count(Carve::parse($before . "\n")->children ?? []);
        }

        return self::$behindReads[$key];
    }

    /**
     * C2-W-090: in a hyphen run the backslash stands before the second hyphen, where that keeps the line a paragraph.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function hyphenRunStaysParagraph(string $line, Context $c): bool
    {
        if (!str_starts_with($line, '--')) {
            return false;
        }
        $escaped = '-\\' . substr($line, 1) . "\n";
        $c->writerState?->chargeRepair($c->textPath, 'first-line-hyphen', $escaped);
        [$count, $type] = self::blockRead($escaped);

        return $count === 1 && $type === 'paragraph';
    }

    /**
     * The number of top blocks a line parses to and the type of the first. The rounds of a leaf test the same line
     * again, so the last read is kept (carve-php#5704).
     *
     * @return array{int, string}
     */
    private static function blockRead(string $source): array
    {
        if (self::$lastBlockRead !== null && self::$lastBlockRead[0] === $source) {
            return self::$lastBlockRead[1];
        }
        $children = Carve::parse($source)->children ?? [];
        $read = [count($children), (string)($children[0]->type ?? '')];
        self::$lastBlockRead = [$source, $read];

        return $read;
    }

    /**
     * A later line interrupts its paragraph by a caption mark, and, inside an item, a description or a footnote, by a
     * list marker or a description mark (C2-B-015 to C2-B-018).
     *
     * @param string $line
     * @param bool $eol
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    private static function laterLine(string $line, bool $eol, Context $c): int
    {
        $ch = $line[0];
        $gap = in_array($line[1] ?? '', [' ', "\t", ''], true);
        if ($ch === '^') {
            // C2-B-146: the mark needs content behind it. C2-B-017, C2-B-147: below paragraph text a caption line is
            // text; only a paragraph that may still be a block image or display math takes one, which the read-back decides.
            if (!$gap || (($line[1] ?? '') !== "\t" && trim(substr($line, 1), " \t") === '') || !self::mayTakeCaption($c)) {
                return -1;
            }

            return self::undecided() ? 0 : -1;
        }
        if (!in_array($c->owner, ['body', 'description'], true)) {
            return -1;
        }
        if ($ch === ':' && $c->owner === 'description') {
            // A comment-only line does not open a definition on the paragraph's next line (C2-B-137).
            if ($c->lineCommentBefore) {
                return -1;
            }

            return $gap || substr($line, 1, 1) === ':' ? 0 : -1;
        }
        if (Markers::quickReject($line, 0, strlen($line))) {
            return -1;
        }
        $marker = Markers::read($line, 0, strlen($line));
        // C2-B-015, C2-B-056: below a paragraph only a bullet or the value 1 opens a list.
        if ($marker === null || ($marker->ordered && $marker->value !== 1 && $marker->value !== -1)) {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * C2-B-147, C2-B-157, C2-B-158: whether the leaf before the text holds nothing but blanks, line breaks, images,
     * display math and comments, so that it may still be a block image or a math block.
     */
    private static function mayTakeCaption(Context $c): bool
    {
        if ($c->flat === null || $c->here < 0) {
            return false;
        }
        for ($k = 0; $k < $c->here; $k++) {
            $ch = $c->flat[$k];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
                continue;
            }
            $type = $ch === "\0" ? $c->flatTypes[$k] ?? '' : '';
            $display = $type === 'math'
                && (($c->flatNodes[$k]['display'] ?? false) === true || str_starts_with($c->flatAtoms[$k] ?? '', '$$'));
            if (!$display && !in_array($type, ['image', 'comment', 'soft_break', 'hard_break'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-B-156: `{{` and `}}` around a whole line. C2-B-090: an attribute line, which attaches only to a block below it,
     * so only on the first line of content that goes on. Neither interrupts a paragraph (C2-N-002), so only a first line.
     *
     * @param string $value
     * @param bool $attaches
     *
     * @return bool
     */
    private static function braceLine(string $value, bool $attaches): bool
    {
        if (str_starts_with($value, '{{') && IncludeShape::parse($value, 0, strlen($value)) !== null) {
            return true;
        }

        return $attaches && LineShapes::attributeLine($value, 0, strlen($value), new Attrs());
    }

    private static function starLine(string $value): bool
    {
        return substr_count($value, '*') >= 3 && trim($value, "* \t") === '';
    }
}
