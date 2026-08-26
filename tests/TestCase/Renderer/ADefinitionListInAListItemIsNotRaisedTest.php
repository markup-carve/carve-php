<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition list inside a LIST ITEM is written at the item's minimum column.
 *
 * The authored-base rule's canonical half is explicit: "Its authored column is
 * the local base of that one complete block; canonical output uses the MINIMUM
 * column" (the Decision in the spec's authored-bases experiment). A `- ` item's
 * minimum content column is 2, so a definition list among its direct children
 * is written at 2.
 *
 * The raise that keeps a description's payload inside its `dd` at a footnote
 * body's minimum column (markup-carve/carve-php#1776) does not apply here: this
 * engine's own parser reads the un-raised bytes back to the same HTML, so there
 * is nothing for the raise to preserve and it only produces a second canonical
 * spelling. It fired anyway, because the raise was gated on a structural
 * predicate that SHORT-CIRCUITED the parser probe that owns the rule.
 *
 * The daily `AST conformance` run caught it on corpus document
 * `423-one-authored-base-rule-reaches-a-definition-nested-in-a-list-item`, where
 * carve-js and carve-rs agreed against this engine on the `carve` target
 * (markup-carve/carve#1802).
 */
class ADefinitionListInAListItemIsNotRaisedTest extends TestCase
{
    private function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testWritesTheDefinitionListAtTheItemsMinimumColumn(): void
    {
        // The corpus document. The payload is a quote, which is exactly the
        // "structural" shape the retired predicate keyed on.
        $source = "- intro\n\n   :: term\n   :  definition\n\n      > quote\n";
        $this->assertSame("- intro\n  :: term\n  : definition\n\n    > quote\n", $this->fmt($source));
    }

    public function testNarrowsEvenWhenTheSourceIsAlreadyCanonical(): void
    {
        // The discriminating case: the definition list is ALREADY at column 2,
        // so a writer that merely PRESERVED an authored column would pass the
        // test above and fail this one - it raised this input to 3 as well.
        // (The blank line above `::` is what normalizes away, not the column.)
        $source = "- intro\n\n  :: term\n  : definition\n\n    > quote\n";
        $this->assertSame("- intro\n  :: term\n  : definition\n\n    > quote\n", $this->fmt($source));
    }

    public function testIsIdempotentAndPreservesTheHtml(): void
    {
        $source = "- intro\n\n   :: term\n   :  definition\n\n      > quote\n";
        $once = $this->fmt($source);
        $this->assertSame($once, $this->fmt($once), 'fmt is idempotent');
        $this->assertSame($this->html($source), $this->html($once), 'PART 11 section 1 holds');
        // The quote has to still be INSIDE the description, not beside it -
        // the loss the raise exists to prevent, asserted on the un-raised form.
        $html = $this->html($once);
        $this->assertGreaterThan(strpos($html, '<dd>'), strpos($html, '<blockquote>'));
        $this->assertLessThan(strpos($html, '</dd>'), strpos($html, '<blockquote>'));
    }

    public function testStillRaisesInsideAFootnoteBodyWhereTheParserNeedsIt(): void
    {
        // The control: the shape the raise was added for must still raise, or
        // this fix would have been "stop raising" rather than "ask the parser".
        $source = "[^n]: intro\n\n  :: term\n  :  definition\n\n     > quote\n\nsee[^n]\n";
        $out = $this->fmt($source);
        $this->assertSame($this->html($source), $this->html($out), 'PART 11 section 1 holds');
        $html = $this->html($out);
        $this->assertGreaterThan(strpos($html, '<dd>'), strpos($html, '<blockquote>'));
        $this->assertLessThan(strpos($html, '</dd>'), strpos($html, '<blockquote>'));
    }
}
