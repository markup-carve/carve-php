<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\ListBlock;
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
 * The separator is not always wrong, which is why the writer asks per case: an
 * ordered marker that does not start at 1 cannot interrupt a paragraph, and a
 * marker with nothing after it reads as a setext underline.
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

    public function testTwoSiblingQuotesInATightItemKeepTheSeparator(): void
    {
        // A quote marker interrupts a PARAGRAPH and nothing else. Dropped here,
        // the second `>` is absorbed by the quote above it and the two merge
        // into one - which CommonMark cannot spell tight, so the separator
        // stays and the item goes out loose.
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
