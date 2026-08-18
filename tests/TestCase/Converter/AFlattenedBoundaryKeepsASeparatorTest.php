<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A flatten preserves the boundary it dissolves - PART 11 section 1b.
 *
 * A slot that takes INLINE content only has nowhere to put a node for a block
 * boundary, so the boundary survives the flatten in the BYTES or not at all -
 * and the bytes are read by a tokenizer. Where two former sibling blocks each
 * contribute at least one TOKEN to the slot, a separator is required between
 * them, and the canonical one is a single space (markup-carve/carve#1325).
 *
 * THE UNIT IS THE TOKEN, NOT THE NODE. A node test passes `onetwo` and
 * `one two` alike, since both are one `text` node, and the difference between
 * them is the whole defect. Nothing is DROPPED in any of these shapes either,
 * so no diagnostic fires and the damage is visible only by reading the text.
 *
 * ALL THREE ENGINES EMITTED THE JOINED FORM, so this is a change in each
 * rather than a divergence between them - there was no reference
 * implementation to copy from.
 */
class AFlattenedBoundaryKeepsASeparatorTest extends TestCase
{
    /**
     * The four rows the ruling pins, as `tests/corpus-convert/29..32`.
     *
     * Asserted on the RENDERED caption rather than on the Carve, because the
     * defect is what the joined bytes re-parse as: `*a**b*` is Carve a reader
     * would call preserved, and only the render shows one strong run holding a
     * literal asterisk.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ruledRowProvider(): array
    {
        return [
            'two paragraphs do not become one word' => [
                '<p>one</p><p>two</p>',
                '<figcaption>one two</figcaption>',
            ],
            'two strong runs do not merge' => [
                '<p><strong>a</strong></p><p><strong>b</strong></p>',
                '<figcaption><strong>a</strong> <strong>b</strong></figcaption>',
            ],
            'two code spans do not merge' => [
                '<p><code>a</code></p><p><code>b</code></p>',
                '<figcaption><code>a</code> <code>b</code></figcaption>',
            ],
            'an empty block is not a side' => [
                '<p>a</p><p></p><p>b</p>',
                '<figcaption>a b</figcaption>',
            ],
        ];
    }

    #[DataProvider('ruledRowProvider')]
    public function testARuledRowRendersAsTwoThings(string $captionContent, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->renderCaption($captionContent));
    }

    /**
     * The empty block is not a side in the SOURCE either, so no author ever
     * gets whitespace they did not write: `a b`, never `a b`.
     */
    public function testAnEmptyBlockContributesNoSeparatorOfItsOwn(): void
    {
        $this->assertSame("![x](/i)\n^ a b", $this->importCaption('<p>a</p><p></p><p>b</p>'));
        $this->assertSame("![x](/i)\n^ a b", $this->importCaption('<p>a</p><p>   </p><p>b</p>'));
    }

    /**
     * Whitespace already at the join IS the separator.
     *
     * The clause's test is that re-reading the slot draws no token from both
     * sides, and a space already there answers it. Emitting a second one would
     * be whitespace neither block wrote.
     */
    public function testWhitespaceAlreadyAtTheJoinIsNotDoubled(): void
    {
        $this->assertSame("![x](/i)\n^ one two", $this->importCaption('<p>one </p><p>two</p>'));
        $this->assertSame("![x](/i)\n^ one two", $this->importCaption('<p>one</p><p> two</p>'));
    }

    /**
     * A NO-BREAK SPACE AT THE JOIN IS A SEPARATOR TOO.
     *
     * The parser reads it as whitespace - `a<nbsp>*b* c` opens a strong run,
     * and `one<nbsp>two` is two words - so the boundary already survives and a
     * second, BREAKABLE space beside it would change where the line wraps.
     */
    public function testANoBreakSpaceAtTheJoinIsNotDoubled(): void
    {
        $nbsp = "\u{a0}";

        $this->assertSame(
            "![x](/i)\n^ a{$nbsp}b",
            $this->importCaption('<p>a&nbsp;</p><p>b</p>'),
        );
        $this->assertSame(
            "![x](/i)\n^ a{$nbsp}b",
            $this->importCaption('<p>a</p><p>&nbsp;b</p>'),
        );
    }

    /**
     * The clause binds every inline-only slot, not the figure caption it was
     * found in. A table caption and the list-table route's quoted fence title
     * are the other two this importer writes.
     */
    public function testTheTableCaptionSlotSeparatesToo(): void
    {
        $carve = (new HtmlToCarve())->convert(
            '<table><caption><p>one</p><p>two</p></caption><tr><td>x</td></tr></table>',
        );

        $this->assertSame("| x |\n^ one two\n", $carve);
    }

    /**
     * A cell and a description are blocks even though the slot does not
     * dissolve them by name: they reach the flatten through the table and
     * definition-list arms instead. Two of them still meet at a block boundary.
     */
    public function testTablePartsAndDefinitionPartsAreSidesToo(): void
    {
        $this->assertSame(
            "![x](/i)\n^ a b",
            $this->importCaption('<table><tr><td>a</td><td>b</td></tr></table>'),
        );
        $this->assertSame(
            "![x](/i)\n^ t d",
            $this->importCaption('<dl><dt>t</dt><dd>d</dd></dl>'),
        );
    }

    /**
     * THE CONTROL THAT KEEPS THE RULE HONEST: an ordinary inline caption gains
     * nothing. Without it, "put a space between every two children" would pass
     * every assertion above and rewrite prose nobody asked about.
     */
    public function testAnInlineCaptionIsUntouched(): void
    {
        $this->assertSame(
            "![x](/i)\n^ plain /and/ marked",
            $this->importCaption('plain <em>and</em> marked'),
        );
        $this->assertSame(
            "![x](/i)\n^ a/b/c",
            $this->importCaption('a<em>b</em>c'),
        );
    }

    /**
     * THE SECOND CONTROL: the rule is bounded to an inline-only slot.
     *
     * The same two paragraphs in an ordinary position keep their own block
     * boundary and take no separator, so nothing outside a flatten moved.
     */
    public function testOutsideAFlattenedSlotNothingChanges(): void
    {
        $this->assertSame("one\n\ntwo\n", (new HtmlToCarve())->convert('<p>one</p><p>two</p>'));
    }

    /**
     * A CHILD THAT EMITS NOTHING DOES NOT CLOSE THE JOIN.
     *
     * The two paragraphs are still the two sides, so the `<script>` between
     * them neither takes a separator nor swallows the one they are owed. This
     * is the same reading as the empty block: a child with no bytes in the slot
     * is not a side.
     */
    public function testAChildThatEmitsNothingDoesNotCloseTheJoin(): void
    {
        $this->assertSame("![x](/i)\n^ a b", $this->importCaption('<p>a</p><script>x=1</script><p>b</p>'));
        $this->assertSame("![x](/i)\n^ a b", $this->importCaption('<p>a</p><style>i{}</style><p>b</p>'));
    }

    /**
     * THE LIMIT OF THE CLAUSE, RECORDED RATHER THAN DECIDED.
     *
     * section 1b is written over two former sibling BLOCKS. A bare text node
     * between two of them is not one, so `<p>a</p>x<p>b</p>` is unchanged by
     * this work and still flattens to `axb` - which is one word by the same
     * test the clause applies to `onetwo`.
     *
     * Asserted so the walk cannot drift into answering it silently: emitting a
     * separator on one side of the text and not the other is worse than either
     * answer. Filed for a ruling rather than picked here, since all three
     * engines would have to move together (markup-carve/carve#1325).
     */
    public function testABareTextNodeBetweenTwoBlocksIsNotDecidedHere(): void
    {
        $this->assertSame("![x](/i)\n^ axb", $this->importCaption('<p>a</p>x<p>b</p>'));
    }

    private function importCaption(string $captionContent): string
    {
        return trim((new HtmlToCarve())->convert(
            '<figure><img src="/i" alt="x"><figcaption>' . $captionContent . '</figcaption></figure>',
        ));
    }

    private function renderCaption(string $captionContent): string
    {
        return (new CarveConverter())->convert($this->importCaption($captionContent));
    }
}
