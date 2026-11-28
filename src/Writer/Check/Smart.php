<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\PlanSmart;

/**
 * The smart punctuation repairs of the comparison (C2-I-104, C2-I-105, C2-W-125 cases two and seven), part of the
 * port of carve-js write-check.ts.
 */
final class Smart
{
    /**
     * Report quote neighbors in a construct's span after its opener escape, except delimiters that cannot open.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node $a
     * @param int $from
     * @param int $i
     */
    public static function escapeInnerQuotes(Check $c, array $es, int $i, int $from, Node $a): void
    {
        if ($a->start === null || $a->end === null) {
            return;
        }
        $quotes = [];
        $pending = $a->children();
        while (($node = array_pop($pending)) !== null) {
            if ($node->type === 'smart_punctuation' && $node->start !== null) {
                $quotes[$node->start] = (string)$node->string('kind');
            }
            array_push($pending, ...$node->children());
        }
        if ($quotes === []) {
            return;
        }
        $e = $es[$i];
        $escapes = $c->w->escapesOf($e);
        $before = count(array_filter($escapes, static fn (int $at): bool => $at < $from));
        $offset = $a->start + strlen($c->w->spell->inline($c->w, $e, $c->flags)) - $from - $before;
        $esCount = count($es);
        for ($k = $i + 1; $k < $esCount && $offset < $a->end; $k++) {
            $node = $es[$k];
            $kind = $quotes[$offset] ?? null;
            $next = Compare::textAt($es, $k + 1);
            if (
                $node->type === 'smart_punctuation' && $kind !== null && $kind !== $node->string('kind') && $next !== null
                && !self::terminalDelimiter($next, $es, $k + 1)
                && !self::readsBackAsText((string)$node->string('source'), (string)$node->string('kind'), $es[$k - 1] ?? null, $next)
            ) {
                self::escapeSmartNeighbor($c, $next, false);
            }
            $offset += strlen($c->w->spell->inline($c->w, $node, $c->flags));
        }
    }

    /**
     * C2-W-125, case two: where the text before a quote gives its kind once the construct is escaped, the right
     * neighbor decides nothing and keeps no backslash; the next round reads the quote again.
     */
    private static function readsBackAsText(string $source, string $kind, ?Node $prior, Node $next): bool
    {
        if ($prior?->type !== 'text' || ($source !== '"' && $source !== "'")) {
            return false;
        }
        $previous = self::lastCodePoint($prior->value ?? '');
        $following = self::firstCodePoint($next->value ?? '');
        if ($previous === null || $following === null) {
            return false;
        }

        return PlanSmart::textQuoteKind($source, $previous, $following) === $kind;
    }

    private static function lastCodePoint(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $chars = mb_str_split($value, 1, 'UTF-8');

        return mb_ord($chars[count($chars) - 1], 'UTF-8');
    }

    private static function firstCodePoint(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        return mb_ord(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8');
    }

    /**
     * C2-I-047: a slash or star with no following character cannot open a paired span.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $next
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param int $index
     */
    private static function terminalDelimiter(Node $next, array $es, int $index): bool
    {
        return ($next->value === '/' || $next->value === '*') && $index === count($es) - 1;
    }

    /**
     * C2-W-125, case seven: escaping a comparison's first character would turn the preceding right quote left.
     */
    public static function comparisonBehindQuote(?Node $prior, ?Node $before, Node $text, int $at, Node $parsed): bool
    {
        $value = $prior?->type === 'text' ? ($prior->value ?? '') : '';
        $neighbor = $value === '' ? '' : $value[strlen($value) - 1];
        $own = $text->value ?? '';

        return $before?->type === 'smart_punctuation'
            && in_array($before->string('kind'), ['right_single_quote', 'right_double_quote'], true)
            && ($neighbor === '/' || $neighbor === '-')
            && $parsed->type === 'smart_punctuation'
            && in_array($parsed->string('kind'), ['not_equal', 'less_than_or_equal', 'greater_than_or_equal'], true)
            && ($own[$at] ?? '') !== '' && str_contains('!<>', $own[$at]) && ($own[$at + 1] ?? '') === '=';
    }

    /**
     * A text quote whose own opener is being escaped can restore the following smart quote on the next parse.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $c
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $es
     * @param \MarkupCarve\Carve\Writer\Check\Node|null $next
     * @param int $i
     */
    public static function escapeSmartSideAfterConstruct(Check $c, array $es, int $i, ?Node $next): bool
    {
        $before = count($c->escapes);
        $changed = self::escapeSmartSide($c, Compare::textAt($es, $i - 1), $next);
        $prior = Compare::textAt($es, $i - 1);
        $value = $prior->value ?? '';
        if ($prior !== null && (str_ends_with($value, '"') || str_ends_with($value, "'"))) {
            $held = false;
            foreach ($c->constructs as $escape) {
                $held = $held || ($escape->node === $prior && $escape->at === strlen($value) - 1);
            }
            if ($held) {
                foreach (array_slice($c->escapes, $before) as $escape) {
                    Check::add($c->behind, $escape);
                }
            }
        }

        return $changed;
    }

    /**
     * C2-I-105: the smart punctuation between `before` and `next` read as another kind; a neighbor takes the backslash.
     */
    public static function escapeSmartSide(Check $c, ?Node $before, ?Node $next): bool
    {
        $leftChanges = Compare::isPending($c, $before);
        if ($next !== null && self::escapeSmartNeighbor($c, $next, $leftChanges)) {
            return true;
        }

        // Where the text before it changes in this round, the next round reads its direction again.
        return $before !== null && !$leftChanges && Compare::escapeAt($c, $before, strlen($before->value ?? '') - 1);
    }

    /**
     * The neighbor of a quote that read in another direction takes the backslash.
     */
    public static function escapeSmartNeighbor(Check $c, Node $next, bool $leftChanges): bool
    {
        if (self::moveSigilEscape($c, $next)) {
            return true;
        }
        $bracket = $leftChanges ? self::sigilBracket($c, $next) : -1;

        return ($bracket > 0 && Compare::escapeAt($c, $next, $bracket)) || self::escapeComparison($c, $next)
            || Compare::escapeAt($c, $next, 0);
    }

    /**
     * The bracket of the sigil opener `:name[` at the start of `next` while it is bare, or -1.
     */
    private static function sigilBracket(Check $c, Node $next): int
    {
        $value = $next->value ?? '';
        if (($value[0] ?? '') !== ':') {
            return -1;
        }
        $bracket = 1;
        $valueLength = strlen($value);
        while ($bracket < $valueLength && self::isExtensionNameChar($value[$bracket])) {
            $bracket++;
        }

        return $bracket === 1 || ($value[$bracket] ?? '') !== '[' || Compare::isEscaped($c, $next, $bracket) ? -1 : $bracket;
    }

    /**
     * C2-I-094: a character of the name of an inline extension after its first.
     */
    public static function isExtensionNameChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '-';
    }

    /**
     * C2-W-125, case seven: the second character of a comparison sign can take the escape without flipping a quote.
     */
    private static function escapeComparison(Check $c, Node $next): bool
    {
        $value = $next->value ?? '';
        if (strlen($value) < 2 || !str_contains('!<>', $value[0]) || $value[1] !== '=') {
            return false;
        }

        return Compare::escapeAt($c, $next, 1);
    }

    /**
     * C2-W-125, case seven: the escape of the sigil opener `:name[` at the start of `next` changed the direction of
     * the quote before it, so the backslash goes before the bracket of that opener.
     */
    private static function moveSigilEscape(Check $c, Node $next): bool
    {
        if (!Compare::isEscaped($c, $next, 0)) {
            return false;
        }
        $bracket = self::sigilBracket($c, $next);
        if ($bracket < 0) {
            return false;
        }
        $c->retracted[] = new Escape($next, 0);
        Compare::mark($c, $next, 0, false);

        return Compare::escapeAt($c, $next, $bracket);
    }
}
