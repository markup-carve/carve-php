<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Every block a list item holds reaches the Markdown target's reader.
 *
 * Two spellings lost one: a task item's continuation was indented to the
 * checkbox rather than to the item's content column, which puts it four columns
 * past where a reader still reads a block, and a sibling block under a nested
 * list arrived with nothing between them, so the reader took it as that list's
 * last item continuing lazily. Both absorb the block into surrounding text and
 * its node is gone (carve-php#2446).
 *
 * The emitted bytes are not the property - what a reader takes from them is. So
 * every case reads its own output back through MarkdownToCarve, which follows
 * cmark-gfm 0.29.0.gfm.13, and each guard carries the control that shows what
 * the other spelling costs.
 */
class AnItemSBlocksSurviveTheMarkdownTargetTest extends TestCase
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

    public function testATaskItemSContinuationGoesToItsContentColumn(): void
    {
        $written = $this->write("- [x] a\n  # h\n");

        $this->assertSame("- [x] a\n  # h\n", $written);
        $this->assertSame([Paragraph::class, Heading::class], $this->blocksInTheFirstItem($written));
        // The control: indented to the checkbox instead, the heading is literal
        // text glued to the item's own paragraph.
        $this->assertSame([Paragraph::class], $this->blocksInTheFirstItem("- [x] a\n      # h\n"));
    }

    public function testAnItemSAttributeBlockDoesNotMoveTheCheckboxEither(): void
    {
        $written = $this->write("-{#k} [x] bare\n  # inside\n");

        $this->assertSame("- [x] bare\n  # inside\n", $written);
        $this->assertSame([Paragraph::class, Heading::class], $this->blocksInTheFirstItem($written));
    }

    public function testAnOrderedMarkerSOwnWidthStillSetsTheColumn(): void
    {
        // The marker IS the content column here, two digits and all, so this is
        // the half of the pad that had to stay.
        $written = $this->write("10. a\n    # h\n");

        $this->assertSame("10. a\n    # h\n", $written);
        $this->assertSame([Paragraph::class, Heading::class], $this->blocksInTheFirstItem($written));
    }

    public function testAParagraphBelowANestedListIsSeparatedFromIt(): void
    {
        $written = $this->write("- - A\n\n  second\n");

        $this->assertSame("- - A\n\n  second\n", $written);
        $this->assertSame([ListBlock::class, Paragraph::class], $this->blocksInTheFirstItem($written));
        // The control: unseparated, the paragraph is absorbed by the sublist
        // item above it and the item holds the sublist alone.
        $this->assertSame([ListBlock::class], $this->blocksInTheFirstItem("- - A\n  second\n"));
    }

    public function testANestedListLeavingNoParagraphOpenKeepsNoSeparator(): void
    {
        // The sublist item ends on a heading, so there is no open paragraph for
        // `lazy` to continue and the separator would only loosen this item.
        $written = $this->write("- a\n  - b\n    # N\nlazy\n");

        $this->assertSame("- a\n  - b\n    # N\n  lazy\n", $written);
        $this->assertSame(
            [Paragraph::class, ListBlock::class, Paragraph::class],
            $this->blocksInTheFirstItem($written),
        );
        $this->assertTrue($this->readsBackTight($written));
        // The control: separated, the same three blocks come back loose.
        $this->assertFalse($this->readsBackTight("- a\n  - b\n    # N\n\n  lazy\n"));
    }

    public function testASiblingSublistKeepsNoSeparatorEither(): void
    {
        $written = $this->write("- outer\n\n  para\n\n  - a\n\n\n\n  - b\n");

        $this->assertSame("- outer\n\n  para\n\n  - a\n  - b\n", $written);
        // Two sibling bullet lists are one list to a reader whatever stands
        // between them, so the separator separates nothing and only changes the
        // merged list's tightness.
        $this->assertSame([2, true], $this->sublistShape($written));
        $this->assertSame([2, false], $this->sublistShape("- outer\n\n  para\n\n  - a\n\n  - b\n"));
    }

    protected function write(string $source): string
    {
        return $this->markdown->convert($source);
    }

    /**
     * The classes of the blocks the first item of this Markdown holds on
     * read-back, which is where an absorbed block shows itself.
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
     * The item count and tightness of the sublist the first item holds.
     *
     * @return array{int, bool}
     */
    protected function sublistShape(string $markdown): array
    {
        foreach ($this->firstItemChildren($markdown) as $child) {
            if ($child instanceof ListBlock) {
                return [count($child->getChildren()), $child->isTight()];
            }
        }

        $this->fail('no sublist in the first item');
    }

    protected function readsBackTight(string $markdown): bool
    {
        return $this->firstList($this->parser->parse($this->reader->convert($markdown)))->isTight();
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function firstItemChildren(string $markdown): array
    {
        $list = $this->firstList($this->parser->parse($this->reader->convert($markdown)));

        return array_values($list->getChildren()[0]->getChildren());
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
