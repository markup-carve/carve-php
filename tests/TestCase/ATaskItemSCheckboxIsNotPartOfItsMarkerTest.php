<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A TASK ITEM'S `[x] ` IS CONTENT, NOT MARKER.
 *
 * `- [x] a` is the bullet `- `, whose width IS the item's content column, and
 * then `[x] `, which the reader consumes as the item's task state. So the item's
 * content column is 2, exactly as it is for `- a` - the checkbox does not move
 * it, and every block the item holds after its first sits at 2.
 *
 * The READER already knew this: BulletMarkerContentColumnTest pins a task item
 * at column 2 whatever the marker's real width. The WRITER did not. It indented
 * every block after the item's first to the full width of what it had put on the
 * marker line - six columns for `- [x] `, ten for `-{#k} [x] ` - which is four
 * past the content column. An ordinary paragraph survives being written there,
 * and that is why this went unseen for so long; a BLOCK OPENER does not, and an
 * indented one opens nothing.
 *
 * carve-js fixed the same site in carve-js#1455. This is the carve-php port
 * (carve-php#1693), under the umbrella markup-carve/carve#1690.
 */
class ATaskItemSCheckboxIsNotPartOfItsMarkerTest extends TestCase
{
    /**
     * The written source is a fixed point AND renders to what the input did -
     * PART 11 section 1: a writer that moves the column changes the document,
     * not only its spelling.
     */
    private function assertHolds(string $source): void
    {
        $written = CarveConverter::toCarve($source);
        $this->assertSame($source, $written);
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($written),
        );
    }

    /**
     * A source that is not already canonical: assert what the writer makes of
     * it, that the render is held across the rewrite, and that a second pass
     * changes nothing.
     */
    private function assertWrites(string $expected, string $source): void
    {
        $written = CarveConverter::toCarve($source);
        $this->assertSame($expected, $written);
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($written),
        );
        $this->assertSame($written, CarveConverter::toCarve($written), 'the writer is not idempotent');
    }

    public function testWritesAHeadingAfterAFloatingAttributeAtTheContentColumn(): void
    {
        $this->assertHolds("- [x] {#h}\n  # h\n");
    }

    public function testWritesAHeadingAfterAFirstParagraphAtTheContentColumn(): void
    {
        $this->assertHolds("- [x] a\n  # h\n");
    }

    public function testWritesAQuoteAfterAFloatingAttributeAtTheContentColumn(): void
    {
        $this->assertHolds("- [ ] {#h}\n  > q\n");
    }

    public function testWritesAFenceAfterAFirstParagraphAtTheContentColumn(): void
    {
        $this->assertHolds("- [x] a\n  ```php\n  1;\n  ```\n");
    }

    /**
     * Attributes are metadata and the checkbox is content; neither moves column 2.
     */
    public function testCountsNeitherItemAttributesNorTheCheckboxIntoTheColumn(): void
    {
        $this->assertHolds("-{#k} [x] {#h}\n  # h\n");
    }

    /**
     * The control: a plain item and an ordered item never had the defect,
     * because their content column and their post-marker column are the same.
     * A fix that subtracted unconditionally would move these.
     */
    public function testLeavesAPlainItemAndAnOrderedItemAlone(): void
    {
        $this->assertHolds("- {#h}\n  # h\n");
        $this->assertHolds("1. {#h}\n   # h\n");
    }

    /**
     * THE THREE CORPUS DOCUMENTS THAT REPORTED IT (markup-carve/carve#1690).
     * Same shape, different nesting, so each is asserted rather than one
     * standing in for the others. Every expectation here is what carve-js
     * writes at its `main`, measured rather than assumed.
     */
    public function testWritesTheCorpusNestedListAtTheContentColumn(): void
    {
        // 05-lists-12
        $this->assertWrites("- [ ] outer\n  - inner\n", "- [ ] outer\n  - inner\n");
    }

    public function testWritesTheCorpusWideMarkerHeadingAtTheContentColumn(): void
    {
        // 75-list-nesting-and-looseness-9. `# H` is paragraph text here, not a
        // heading, so the writer escapes it - at column 6 it was text of the
        // marker line's paragraph by ACCIDENT of the indent.
        $this->assertWrites("- [ ] item\n  # H\n", "-   [ ] item\n    # H\n");
    }

    public function testWritesTheCorpusNestedQuoteAtTheContentColumn(): void
    {
        // 144-nested-item-looseness-does-not-propagate-to-the-outer-item-3
        $this->assertWrites("- [ ] a\n  - b\n    > q\n", "- [ ] a\n  - b\n\n    > q\n");
    }
}
