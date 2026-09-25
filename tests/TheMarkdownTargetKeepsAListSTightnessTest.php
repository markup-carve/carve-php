<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10l [CARVE-P11-047]: the Markdown target keeps a list's tightness.
 *
 * THE EMITTED BYTES ARE NOT THE PROPERTY UNDER TEST - the tightness a reader
 * takes from them is. So every case below reads its own output back through
 * MarkdownToCarve, which follows cmark-gfm 0.29.0.gfm.13, and asserts the flag
 * rather than the spelling. A list nested under `- parent` used to go out with a
 * blank line above it, and a reader answers that blank with `<li><p>parent</p>`
 * where the document's own HTML says `<li>parent`.
 *
 * The clause names a nested list and a block quote as examples of an opener that
 * interrupts a paragraph, and the property is what governs: a heading, a fence
 * and a GFM table have it too (ruled on markup-carve/carve-rs#1914). The
 * separator is not always wrong, which is why the writer asks per case, and the
 * question goes to the emitted LINE - `---` under a paragraph is a setext
 * underline, so it changes what that paragraph is instead of interrupting it.
 *
 * Three of the guards below score HIGHER on a corpus tightness count when
 * removed, because gluing two quotes or two tables into one leaves the item
 * tight while destroying a block. Each therefore carries a control asserting how
 * the glued spelling reads back, never a count.
 */
class TheMarkdownTargetKeepsAListSTightnessTest extends TestCase
{
    protected CarveConverter $markdown;

    protected MarkdownToCarve $reader;

    protected BlockParser $parser;

    protected function setUp(): void
    {
        $this->markdown = CarveConverter::markdown();
        $this->reader = new MarkdownToCarve();
        $this->parser = new BlockParser();
    }

    public function testANestedListDoesNotLoosenTheItemAboveIt(): void
    {
        $source = "- parent\n  - child\n";

        $this->assertSame("- parent\n  - child\n", $this->write($source));
        $this->assertTrue($this->tightInSource($source));
        $this->assertTrue($this->readsBackTight($source));
    }

    public function testANestedQuoteDoesNotLoosenTheItemAboveIt(): void
    {
        $source = "- a\n  > - x\n\n  - m\n";

        $this->assertSame("- a\n  > - x\n  - m\n", $this->write($source));
        $this->assertTrue($this->tightInSource($source));
        $this->assertTrue($this->readsBackTight($source));
    }

    public function testAFlatLooseListReadsBackLoose(): void
    {
        $source = "- a\n\n- b\n";

        $this->assertSame("- a\n\n- b\n", $this->write($source));
        $this->assertFalse($this->tightInSource($source));
        $this->assertFalse($this->readsBackTight($source));
    }

    public function testALooseListKeepsItsLoosenessAtEveryItemBoundary(): void
    {
        $source = "- a\n\n- b\n\n- c\n";

        $this->assertSame("- a\n\n- b\n\n- c\n", $this->write($source));
        $this->assertFalse($this->readsBackTight($source));
    }

    public function testAnOrderedMarkerThatCannotInterruptKeepsTheSeparator(): void
    {
        // `3.` below a paragraph line is text to a reader, so the blank has to
        // stay or the nested list stops being a list at all.
        $source = "- a\n  3. b\n";

        $written = $this->write($source);
        $this->assertSame("- a\n\n  3. b\n", $written);
        $this->assertSame("{loose}\n- a\n\n  3. b\n", $this->reader->convert($written));
        // The control: the same output without the blank comes back as text.
        $this->assertSame("- a\n  3\\. b\n", $this->reader->convert("- a\n  3. b\n"));
    }

    public function testAnOrderedMarkerStartingAtOneDropsTheSeparator(): void
    {
        $source = "- a\n  1. b\n";

        $this->assertSame("- a\n  1. b\n", $this->write($source));
        $this->assertTrue($this->readsBackTight($source));
    }

    public function testABareNestedMarkerKeepsTheSeparator(): void
    {
        // The nested item holds only a comment, which this target drops, so its
        // marker goes out bare. A lone `-` under a paragraph line is a setext
        // underline, which would turn the item's text into a heading instead.
        $source = "- a\n  - %% c\n";

        $written = $this->write($source);
        $this->assertSame("- a\n\n  -\n", $written);
        $this->assertSame("- a\n\n  -\n", $this->reader->convert($written));
        // The control: the same output without the blank folds the item's text
        // into a heading.
        $this->assertSame("- ## a\n", $this->reader->convert("- a\n  -\n"));
    }

    public function testAHeadingDoesNotLoosenTheItemAboveIt(): void
    {
        $source = "- a\n  # h\n";

        $this->assertSame("- a\n  # h\n", $this->write($source));
        $this->assertTrue($this->tightInSource($source));
        $this->assertTrue($this->readsBackTight($source));
        $this->assertSame([Paragraph::class, Heading::class], $this->blocksInTheFirstItem($this->write($source)));
    }

    public function testAFenceDoesNotLoosenTheItemAboveIt(): void
    {
        $source = "- a\n  ```\n  x\n  ```\n";

        $this->assertSame("- a\n  ```\n  x\n  ```\n", $this->write($source));
        $this->assertTrue($this->readsBackTight($source));
        $this->assertSame([Paragraph::class, CodeBlock::class], $this->blocksInTheFirstItem($this->write($source)));
    }

    public function testATableDoesNotLoosenTheItemAboveIt(): void
    {
        $source = "- one\n  | H |\n  | --- |\n  | x |\n";

        $this->assertSame("- one\n  | H |\n  | --- |\n  | x |\n", $this->write($source));
        $this->assertTrue($this->readsBackTight($source));
        $this->assertSame([Paragraph::class, Table::class], $this->blocksInTheFirstItem($this->write($source)));
    }

    public function testAHeadingBelowAQuoteDropsTheSeparatorToo(): void
    {
        // The quote closes on its own line, so the heading opens under it: the
        // seam asks what the block BELOW writes, not what stands above it.
        $source = "- intro\n  > q\n\n  # h\n";

        $written = $this->write($source);
        $this->assertSame("- intro\n  > q\n  # h\n", $written);
        $this->assertTrue($this->readsBackTight($source));
        $this->assertSame(
            [Paragraph::class, BlockQuote::class, Heading::class],
            $this->blocksInTheFirstItem($written),
        );
    }

    public function testAThematicBreakKeepsTheSeparator(): void
    {
        // `---` under a paragraph line is a SETEXT HEADING, not a break: its
        // opener does not interrupt the paragraph, it changes what the paragraph
        // is. No corpus document spells this, so only this case holds it.
        $source = "- a\n+\n---\n";

        $written = $this->write($source);
        $this->assertSame("- a\n\n  ---\n", $written);
        $this->assertSame(
            [Paragraph::class, ThematicBreak::class],
            $this->blocksInTheFirstItem($written),
        );
        // The control: dropped, the paragraph above is underlined into a heading
        // and the break is gone.
        $this->assertSame([Heading::class], $this->blocksInTheFirstItem("- a\n  ---\n"));
    }

    public function testTwoSiblingTablesInATightItemKeepTheSeparator(): void
    {
        // A row is paragraph continuation text until a delimiter row promotes
        // it, so a table below a table is read as more rows of the first. The
        // merged item comes back TIGHT, which is why a count cannot hold this.
        $source = "- x\n+\n| a |\n| --- |\n| b |\n+\n| a |\n| --- |\n| b |\n";

        $written = $this->write($source);
        $this->assertSame("- x\n  | a |\n  | --- |\n  | b |\n\n  | a |\n  | --- |\n  | b |\n", $written);
        $this->assertSame([2, 2], $this->tableRowsInTheFirstItem($written));
        // The control: dropped, the two become one table whose second delimiter
        // row is a data cell.
        $glued = "- x\n  | a |\n  | --- |\n  | b |\n  | a |\n  | --- |\n  | b |\n";
        $this->assertSame([5], $this->tableRowsInTheFirstItem($glued));
    }

    public function testAHeaderlessTableRowKeepsTheSeparator(): void
    {
        // Without a delimiter row below it the row never opens a table, so
        // unseparated it is swallowed by the paragraph above as continuation
        // text - one block where the item held two.
        $source = "- item\n  | a | b |\n";

        $written = $this->write($source);
        $this->assertSame("- item\n\n  | a | b |\n", $written);
        $this->assertSame([Paragraph::class, Paragraph::class], $this->blocksInTheFirstItem($written));
        // The control: dropped, the row folds into the paragraph above it.
        $this->assertSame([Paragraph::class], $this->blocksInTheFirstItem("- item\n  | a | b |\n"));
    }

    public function testTwoSiblingQuotesInATightItemKeepTheSeparator(): void
    {
        // An unseparated `>` under an OPEN quote continues that quote instead of
        // opening one. Dropped here, the two merge into a single quote - which
        // CommonMark cannot spell tight, so the separator stays and the item
        // goes out loose.
        $source = "- x\n+\n> q\n+\n> q\n";

        $written = $this->write($source);
        $this->assertSame("- x\n  > q\n\n  > q\n", $written);
        $this->assertSame(2, $this->quotesInTheFirstItem($written));
        // The control: dropped, the two quotes come back as one.
        $this->assertSame(1, $this->quotesInTheFirstItem("- x\n  > q\n  > q\n"));
    }

    public function testADroppedChildDoesNotHideTheSeamAboveTheNestedList(): void
    {
        // The comment between the two is written as nothing, so the paragraph
        // that left the separator behind is two positions back and the seam has
        // to be read off the text rather than off the sibling index.
        $source = "- a\n  %% c\n  - b\n";

        $this->assertSame("- a\n  - b\n", $this->write($source));
        $this->assertTrue($this->readsBackTight($source));
    }

    public function testATightListOfPlainItemsIsUnchanged(): void
    {
        $source = "- a\n- b\n";

        $this->assertSame("- a\n- b\n", $this->write($source));
        $this->assertTrue($this->readsBackTight($source));
    }

    protected function write(string $source): string
    {
        return $this->markdown->convert($source);
    }

    protected function tightInSource(string $source): bool
    {
        return $this->firstList($this->parser->parse($source))->isTight();
    }

    /**
     * The emitted Markdown read back the way a cmark-gfm reader takes it.
     */
    protected function readBack(string $source): Node
    {
        return $this->parser->parse($this->reader->convert($this->write($source)));
    }

    protected function readsBackTight(string $source): bool
    {
        return $this->firstList($this->readBack($source))->isTight();
    }

    /**
     * The classes of the blocks the first item of this Markdown holds on
     * read-back, which is where a merged block shows itself.
     *
     * @return list<class-string>
     */
    protected function blocksInTheFirstItem(string $markdown): array
    {
        $blocks = [];
        foreach ($this->firstItemChildren($markdown) as $child) {
            $blocks[] = $child::class;
        }

        return $blocks;
    }

    /**
     * The row count of every table the first item holds, in order.
     *
     * @return list<int>
     */
    protected function tableRowsInTheFirstItem(string $markdown): array
    {
        $rows = [];
        foreach ($this->firstItemChildren($markdown) as $child) {
            if ($child instanceof Table) {
                $rows[] = count($child->getChildren());
            }
        }

        return $rows;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function firstItemChildren(string $markdown): array
    {
        $list = $this->firstList($this->parser->parse($this->reader->convert($markdown)));

        return array_values($list->getChildren()[0]->getChildren());
    }

    /**
     * How many block quotes the first item of this Markdown holds on read-back.
     */
    protected function quotesInTheFirstItem(string $markdown): int
    {
        $quotes = 0;
        $list = $this->firstList($this->parser->parse($this->reader->convert($markdown)));
        foreach ($list->getChildren()[0]->getChildren() as $child) {
            if ($child instanceof BlockQuote) {
                $quotes++;
            }
        }

        return $quotes;
    }

    protected function firstList(Node $node): ListBlock
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                return $child;
            }
        }

        $this->fail('no list at the top level');
    }
}
