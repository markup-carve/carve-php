<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PART 9 §11 N1a's boundary applies at EVERY level, so an item can hold two
 * sibling sub-lists - and the canonical writer could not spell one.
 *
 * A tight item joins its children so the re-parse stays tight, and where two of
 * them would merge it wrote both behind §17 L3's `+` marker at the item's MARKER
 * column. That column is column 0, which is where the list the item belongs to
 * writes its own markers: a sub-list put there is not attached to the item, it
 * is dissolved into the list around it. The ticket document came back as one
 * flat list of three items, with both sub-lists and the boundary between them
 * gone, so `toHtml(fmt(x)) == toHtml(x)` failed (markup-carve/carve#1501).
 *
 * The remedy is that a sub-list is written at the item's CONTENT column, with
 * whatever separator the block above it needs: the boundary when that block is a
 * list it would merge with, one blank line when it is a block that would read
 * the sub-list as its own continuation, and nothing at all otherwise.
 *
 * THE ASSERTIONS COMPARE RE-PARSES, not bytes of HTML with the escaping
 * forgiven: shape() is the tree the reader gets back, and an equal-HTML check
 * alone is exactly what let the sibling defects in this area sit unnoticed.
 *
 * The spellings are byte-identical to carve-js (markup-carve/carve-js#1299),
 * which is the oracle this port was measured against.
 */
class TwoSiblingSubListsInATightItemTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * The block tree as nested type names - inline content and positions
     * dropped, so only the nesting the reader gets back is compared.
     */
    private function shape(string $source): string
    {
        return $this->project($this->converter->parse($source));
    }

    private function project(Node $node): string
    {
        $name = (new ReflectionClass($node))->getShortName();
        $inner = [];
        foreach ($node->getChildren() as $child) {
            $kind = (new ReflectionClass($child))->getShortName();
            if ($kind === 'ListBlock' || $kind === 'ListItem' || $kind === 'BlockQuote') {
                $inner[] = $this->project($child);
            }
        }

        return $inner === [] ? $name : $name . '(' . implode(',', $inner) . ')';
    }

    /**
     * Every property PART 11 §1 asks of the writer, on one document.
     */
    private function assertRoundTrips(string $source): void
    {
        $once = $this->converter->toCarve($source);
        $this->assertSame($this->shape($source), $this->shape($once));
        $this->assertSame($this->converter->convert($source), $this->converter->convert($once));
        $this->assertSame($once, $this->converter->toCarve($once));
    }

    /**
     * A line of nothing but spaces or tabs is not a form the writer may emit
     * (PART 11 §7), and it is what the first attempt at this fix produced above
     * the second list.
     */
    private function assertNoWhitespaceOnlyLine(string $text): void
    {
        foreach (explode("\n", $text) as $line) {
            if ($line !== '') {
                $this->assertNotSame('', trim($line), 'whitespace-only line: ' . var_export($text, true));
            }
        }
    }

    public function testWritesTheTicketDocumentBackAsTheAuthorWroteIt(): void
    {
        $source = "- outer\n\n  - a\n\n\n\n  - b\n";

        $this->assertSame(
            'Document(ListBlock(ListItem(ListBlock(ListItem),ListBlock(ListItem))))',
            $this->shape($source),
        );
        $this->assertSame("- outer\n  - a\n\n\n\n  - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
    }

    public function testDoesNotPutTheSubListsAtTheMarkerColumn(): void
    {
        // The failure was not "some other spelling": at column 0 the `- b` is an
        // item of the OUTER list, so the document loses a level of nesting.
        $written = $this->converter->toCarve("- outer\n\n  - a\n\n\n\n  - b\n");

        $this->assertStringNotContainsString("\n+\n", $written);
        $this->assertSame(
            ['- outer'],
            array_values(array_filter(explode("\n", $written), static fn (string $line): bool => str_starts_with($line, '- '))),
        );
    }

    public function testLeavesNoWhitespaceOnlyLineAboveTheSecondList(): void
    {
        $this->assertNoWhitespaceOnlyLine($this->converter->toCarve("- outer\n\n  - a\n\n\n\n  - b\n"));
        $this->assertNoWhitespaceOnlyLine($this->converter->toCarve("> - outer\n>\n>   - a\n>\n>\n>\n>   - b\n"));
        $this->assertNoWhitespaceOnlyLine($this->converter->toCarve("- x\n\n  > - a\n  >\n  >\n  >\n  > - b\n"));
    }

    public function testSpellsTheBoundaryAsExactlyThreeBlankLines(): void
    {
        // §10i fixes the length at three, whatever run the author wrote.
        $this->assertStringContainsString(
            "- a\n\n\n\n  - b",
            $this->converter->toCarve("- outer\n\n  - a\n\n\n\n  - b\n"),
        );
    }

    public function testCollapsesALongerRunToThreeInsideAnItemToo(): void
    {
        // The nested analogue of corpus 395: a decorative run still normalizes,
        // and the boundary is not a decorative run.
        $six = "- outer\n\n  - a\n\n\n\n\n\n\n  - b\n";

        $this->assertSame("- outer\n  - a\n\n\n\n  - b\n", $this->converter->toCarve($six));
        $this->assertRoundTrips($six);
    }

    public function testSeparatesAThirdAndAFourthSubListTheSameWay(): void
    {
        $three = "- outer\n\n  - a\n\n\n\n  - b\n\n\n\n  - c\n";

        $this->assertSame("- outer\n  - a\n\n\n\n  - b\n\n\n\n  - c\n", $this->converter->toCarve($three));
        $this->assertRoundTrips($three);
        $this->assertRoundTrips("- o\n\n  - a\n\n\n\n  - b\n\n\n\n  - c\n\n\n\n  - d\n");
    }

    public function testSeparatesSubListsThatHoldMoreThanOneItem(): void
    {
        $this->assertRoundTrips("- outer\n\n  - a\n  - a2\n\n\n\n  - b\n  - b2\n");
    }

    public function testCarriesTheBoundaryThroughOrderedBulletAndTaskMarkers(): void
    {
        $this->assertRoundTrips("1. outer\n\n   1. a\n\n\n\n   1. b\n");
        $this->assertRoundTrips("1. outer\n\n   - a\n\n\n\n   - b\n");
        $this->assertRoundTrips("- outer\n\n  - [ ] a\n\n\n\n  - [ ] b\n");
    }

    public function testSeparatesSubListsTwoLevelsDown(): void
    {
        $source = "- L1\n\n  - L2\n\n    - a\n\n\n\n    - b\n";

        $this->assertSame("- L1\n  - L2\n    - a\n\n\n\n    - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
    }

    public function testSeparatesSubListsInTheSecondItemOfAList(): void
    {
        $this->assertRoundTrips("- one\n- two\n\n  - a\n\n\n\n  - b\n");
    }

    public function testSeparatesSubListsBelowAFencedBlockInTheSameItem(): void
    {
        $this->assertRoundTrips("- x\n\n  ```\n  c\n  ```\n\n  - a\n\n\n\n  - b\n");
        $this->assertRoundTrips("- x\n\n  > q\n\n  - a\n\n\n\n  - b\n");
    }

    public function testSeparatesSubListsInALooseItem(): void
    {
        // The loose path is renderBlocks(), which spliced the boundary BETWEEN
        // two rendered blocks. The splice hid the line break from the item's
        // indent pass, so `- b` came back at column 0 and left the item.
        $source = "- outer\n\n  para\n\n  - a\n\n\n\n  - b\n";

        $this->assertSame("- outer\n\n  para\n\n  - a\n\n\n\n  - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
        $this->assertRoundTrips("- outer\n\n  - a\n\n\n\n  - b\n\n  tail\n");
    }

    public function testSpellsTheBoundaryWithTheHostPrefixInsideABlockquote(): void
    {
        // A blockquote writes its own blank line as `>`, so the three blank
        // lines the boundary opens are `>` lines - an empty line would end the
        // quote and take the second list out of it.
        $source = "> - outer\n>\n>   - a\n>\n>\n>\n>   - b\n";

        $this->assertSame("> - outer\n>   - a\n>\n>\n>\n>   - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
    }

    public function testSpellsTheBoundaryWithEveryHostPrefixHoweverDeep(): void
    {
        // The prefix is read off the line the tag opens, so no host has to know
        // the boundary exists - and a host nested inside another gets both
        // halves. A nested quote writes `> >`, a quote inside a list item writes
        // `  >`, and a description writes nothing at its three-column indent.
        $this->assertSame(
            "> > - a\n> >\n> >\n> >\n> > - b\n",
            $this->converter->toCarve("> > - a\n> >\n> >\n> >\n> > - b\n"),
        );
        $this->assertRoundTrips("> > - a\n> >\n> >\n> >\n> > - b\n");
        $this->assertRoundTrips("- x\n\n  > - a\n  >\n  >\n  >\n  > - b\n");
        $this->assertRoundTrips("- x\n\n  > - o\n  >\n  >   - a\n  >\n  >\n  >\n  >   - b\n");
        $this->assertSame(
            ":: t\n: - a\n\n\n\n  - b\n",
            $this->converter->toCarve(":: t\n: - a\n\n\n\n  - b\n"),
        );
        $this->assertRoundTrips(":: t\n: - a\n\n\n\n  - b\n");
        $this->assertRoundTrips("::: note\n- a\n\n\n\n- b\n:::\n");
    }

    public function testKeepsTheTopLevelBoundaryExactlyAsItWas(): void
    {
        // The control for the mechanism change: the boundary tag moved from a
        // splice between two blocks to the head of the second one, and at
        // document level nothing may move with it.
        $this->assertSame("- apples\n\n\n\n- oranges\n", $this->converter->toCarve("- apples\n\n\n\n- oranges\n"));
        $this->assertSame("1. a\n\n\n\n1. b\n", $this->converter->toCarve("1. a\n\n  1. b\n"));
    }

    public function testWritesOneBlankLineBelowABlockAtTheMarkerColumn(): void
    {
        // §17 L3 puts the attached paragraph at column 0, and a sub-list at the
        // item's content column below it is INDENTED under an open paragraph, so
        // it reads as that paragraph's lazy continuation and never opens.
        $source = "- x\n+\np2\n\n  - b\n";

        $this->assertSame("- x\n+\np2\n\n  - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
    }

    public function testWritesOneBlankLineBelowABlockquote(): void
    {
        // A quote takes a non-blank line below it as lazy continuation, so the
        // sub-list became text inside the quote. That shape carries no §11 N1a
        // boundary at all - it is the same question and the same answer.
        $source = "- x\n  > q\n\n  - b\n";

        $this->assertSame("- x\n  > q\n\n  - b\n", $this->converter->toCarve($source));
        $this->assertRoundTrips($source);
        $this->assertRoundTrips("- x\n\n  - a\n\n  > q\n\n  - b\n");
    }

    public function testWritesOneBlankLineBelowEveryKindThatLeavesAParagraphOpen(): void
    {
        // Each member of the set is load-bearing rather than carried along for
        // symmetry: with a sub-list already open at the item's content column,
        // all four of these lose the second sub-list without the blank line.
        foreach (['para', '![a](i.png)', "![a](i.png)\n  ^ cap", ":: t\n  : d"] as $above) {
            $this->assertRoundTrips("- o\n  - z\n  | t |\n  " . $above . "\n\n  - s1\n");
        }
    }

    public function testWritesNoSeparatorWhereNothingAboveReachesDown(): void
    {
        // The bound on the rule: a heading, fence, table, break, div or
        // admonition closes at its last line, so the sub-list opens on the next
        // one and owes nothing. A blank line here would be a construct the
        // document did not have.
        $this->assertSame("- x\n  # h\n  - b\n", $this->converter->toCarve("- x\n\n  # h\n\n  - b\n"));
        $this->assertSame("- x\n  | a |\n  - b\n", $this->converter->toCarve("- x\n\n  | a |\n\n  - b\n"));
        $this->assertSame("- x\n  ***\n  - b\n", $this->converter->toCarve("- x\n\n  ***\n\n  - b\n"));
        $this->assertSame("- outer\n  - a\n", $this->converter->toCarve("- outer\n\n  - a\n"));
    }

    public function testLeavesTheMarkerColumnToTheKindsThatStillNeedIt(): void
    {
        // Two sibling blockquotes, tables, line blocks and definition lists
        // merge when written adjacent and CAN be attached at column 0, because
        // none of them opens there in preference to being attached. They keep
        // the `+`.
        $this->assertStringContainsString("\n+\n", $this->converter->toCarve("- outer\n\n  > a\n\n  > b\n"));
        $this->assertStringContainsString("\n+\n", $this->converter->toCarve("- outer\n\n  | a |\n\n  | a |\n"));
        $this->assertRoundTrips("- outer\n\n  > a\n\n  > b\n");
        $this->assertRoundTrips("- outer\n\n  | a |\n\n  | a |\n");
    }

    public function testOwesNothingToSubListsWhoseMarkersAlreadyDiffer(): void
    {
        // carve#286's axis: different markers separate on their own, so no
        // boundary is written and the author's adjacency survives.
        $this->assertSame("- outer\n  - a\n  * b\n", $this->converter->toCarve("- outer\n\n  - a\n\n  * b\n"));
        $this->assertRoundTrips("- outer\n\n  - a\n\n\n\n  * b\n");
    }

    public function testKeepsAnOrdinaryBlankRunAboveANonList(): void
    {
        // A boundary above a block that is NOT a list is a decorative run and
        // still normalizes to one blank line - the control that the boundary is
        // written only where §11 N1's merge rule would otherwise apply.
        $this->assertRoundTrips("- x\n\n  para\n\n\n\n  - b\n");
        $this->assertRoundTrips("- x\n\n  ```\n  c\n  ```\n\n\n\n  - b\n");
    }
}
