<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Node;

/**
 * The one authoring hazard around the fenced block quote that no other
 * diagnostic reaches (markup-carve/carve#1718).
 *
 * A `::: >` opener written at the column of the quote above it is a block
 * opener, so it ends that quote and starts a sibling one:
 *
 *     > a
 *     ::: >
 *     b
 *     :::
 *
 * renders two adjacent `<blockquote>` elements. That is ordinary container
 * behavior and correct - nesting needs the marker, `> ::: >`.
 *
 * It is reported because the failure is INVISIBLE for this container kind
 * alone. Written with any other type token the mistake produces a visibly
 * different element and the author notices; written with `::: >` it produces
 * the two blockquotes above, which read exactly like the nesting that was
 * intended. Nothing is malformed, so no other rule fires and `carve lint`
 * exits 0. The two hazards beside it already have a rule: a closer on the
 * wrong side of the marker leaves the fence unclosed to end of input.
 *
 * A tree pass rather than a line scan, because the relation is between two
 * SIBLING blocks - which the source lines cannot state at any column, since
 * an indented `::: >` under an item is the same mistake at a different column.
 * Rule id and message mirror carve-js's `lint.ts`, the parity reference: an
 * id is spec surface and anything keyed on it (a CI filter, an editor
 * suppression) is unshareable when two engines spell it differently.
 */
class QuoteFenceLinter
{
    /**
     * @var string
     */
    public const RULE_QUOTE_FENCE_ENDS_THE_QUOTE_ABOVE = 'quote-fence-ends-the-quote-above';

    /**
     * @var int
     */
    private const MAX_WALK_DEPTH = 512;

    /**
     * @param string $source
     * @param array<string, mixed> $options Accepted for signature parity with
     *   the other linters; this pass reads none.
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);

        $warnings = [];
        $this->collect($document, SourceOffsets::map($source), strlen($source), $warnings);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     * @param int $depth
     */
    private function collect(Node $node, ?array $byteAt, int $sourceLength, array &$warnings, int $depth = 0): void
    {
        if ($depth >= self::MAX_WALK_DEPTH) {
            return;
        }

        $previous = null;
        foreach ($node->getChildren() as $child) {
            if ($previous !== null && $this->sitsOnTheQuoteAbove($previous, $child)) {
                $warnings[] = $this->warn($child, $byteAt, $sourceLength);
            }
            $previous = $child;
            $this->collect($child, $byteAt, $sourceLength, $warnings, $depth + 1);
        }
    }

    /**
     * Whether a fenced quote opens on the line directly below a PREFIXED one.
     *
     * Both halves of that are narrowing. A blank line between them makes two
     * quotes deliberate, and after a closing fence a sibling is where it looks,
     * so a fenced quote below a fenced quote is not reported either.
     *
     * @param \MarkupCarve\Carve\Node\Node $previous
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    private function sitsOnTheQuoteAbove(Node $previous, Node $node): bool
    {
        if (!$node instanceof BlockQuote || !$node->isFenced()) {
            return false;
        }
        if (!$previous instanceof BlockQuote || $previous->isFenced()) {
            return false;
        }

        $above = $previous->getPos();
        $opener = $node->getPos();
        if ($above === null || $opener === null) {
            return false;
        }

        return $opener->startLine === $above->endLine + 1;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     */
    private function warn(Node $node, ?array $byteAt, int $sourceLength): LintWarning
    {
        $message = 'A "::: >" opener at the column of the quote above it ENDS that quote and opens a sibling one; '
            . 'the two render as adjacent blockquotes, not one nested in the other. '
            . 'Write "> ::: >" to nest it, or leave a blank line to make two quotes deliberate.';

        $pos = $node->getPos();
        // A node the parser could not place carries NO span - PART 12 §4
        // forbids inventing one - so the finding falls back to the document
        // start rather than to a position it made up.
        if ($pos === null) {
            return new LintWarning(1, 1, self::RULE_QUOTE_FENCE_ENDS_THE_QUOTE_ABOVE, $message, 0, 0);
        }

        return new LintWarning(
            $pos->startLine,
            $pos->startColumn,
            self::RULE_QUOTE_FENCE_ENDS_THE_QUOTE_ABOVE,
            $message,
            SourceOffsets::toByte($pos->startOffset, $byteAt, $sourceLength),
            SourceOffsets::toByte($pos->endOffset, $byteAt, $sourceLength),
        );
    }
}
