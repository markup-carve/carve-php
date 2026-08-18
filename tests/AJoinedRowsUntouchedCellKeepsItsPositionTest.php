<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: a cell the continuation row never touched keeps its position.
 *
 * A `+` row makes every cell of the row it joins non-verbatim, because
 * `mergeCellContents()` trims and the rebuild then compares the trimmed
 * content against the padded original. The direct cell map declines on that,
 * and the fallback map declines too on a cell carrying a `=`, `<`, `>` or `:`
 * marker, because its chunk still holds the run that `parseTableCellMarker()`
 * has already taken off the content the node was built from.
 *
 * The joined cell itself is the §4 case that permits an omission - its value
 * is not a contiguous slice at any offset, and carve-js and carve-rs omit it
 * too (carve-php#1361). Its untouched NEIGHBOUR is not: it is an ordinary run
 * of its own line, and it came back unplaced where both other engines place
 * it (markup-carve/carve-php#1450, corpus 354 and 355-2).
 */
class AJoinedRowsUntouchedCellKeepsItsPositionTest extends TestCase
{
    private function parse(string $source): Table
    {
        $converter = CarveConverter::create(
            new BlockParser(false, false, false, true),
            new HtmlRenderer(),
        );
        $document = $converter->parse($source);
        $node = $document->getChildren()[0];
        while (!$node instanceof Table) {
            $node = $node->getChildren()[0];
        }

        return $node;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private function cells(Table $table): array
    {
        return $table->getChildren()[0]->getChildren();
    }

    /**
     * Corpus 354. The second header cell is a plain run of line 1 and has
     * nothing to do with the join, so its text is placed and slices back.
     */
    public function testTheUntouchedHeaderCellsTextIsPlaced(): void
    {
        $source = "|=a |=b |\n+ cont |\n";
        $cells = $this->cells($this->parse($source));
        $text = $cells[1]->getChildren()[0];

        $this->assertInstanceOf(Text::class, $text);
        $position = $text->getPos();
        $this->assertNotNull($position, 'an untouched cell lost its position to its neighbour\'s join');
        $this->assertSame(
            $text->getContent(),
            substr($source, $position->startOffset, $position->endOffset - $position->startOffset),
            'the span selects bytes the node does not hold',
        );
    }

    /**
     * Corpus 355-2. The table reaches the cell splitter already re-indented,
     * so the column the split reported is short by the strip - the chunk has
     * to be re-anchored against the real source line before it is published.
     */
    public function testAnIndentedJoinedRowsCellIsPlacedAgainstTheRealLine(): void
    {
        $source = "- | a |\n  + |\ntail\n";
        $cells = $this->cells($this->parse($source));
        $text = $cells[0]->getChildren()[0];

        $this->assertInstanceOf(Text::class, $text);
        $position = $text->getPos();
        $this->assertNotNull($position, 'the stripped indent cost the cell its position');
        $this->assertSame(
            $text->getContent(),
            substr($source, $position->startOffset, $position->endOffset - $position->startOffset),
            'the span selects bytes the node does not hold',
        );
    }

    /**
     * And it anchors to the RIGHT bytes, which is why the correction is a
     * measured per-row delta and not a search.
     *
     * A search would have to accept the first match at or after the copied
     * column. `> - | - |` puts the LIST MARKER there, so the cell's `-` took
     * the marker's offset; and two cells holding the same text both took the
     * earlier one. Both were introduced by the first shape of this fix and
     * raised by codex review.
     *
     * @return array<string, array{string, int, int}> source, cell index, expected start offset
     */
    public static function anchoringTraps(): array
    {
        return [
            'a container marker matching the cell text' => ["> - | - |\n>   + |\n", 0, 6],
            'two cells holding the same text' => ["> - | - | - |\n>   + |\n", 1, 10],
            'a list item, first cell' => ["- | a |\n  + |\n", 0, 4],
        ];
    }

    #[DataProvider('anchoringTraps')]
    public function testTheChunkIsAnchoredAgainstTheRealSourceColumn(
        string $source,
        int $cell,
        int $expected,
    ): void {
        $text = $this->cells($this->parse($source))[$cell]->getChildren()[0];

        $this->assertInstanceOf(Text::class, $text);
        $position = $text->getPos();
        $this->assertNotNull($position, 'the cell lost its position');
        $this->assertSame($expected, $position->startOffset, 'the chunk was anchored to the wrong bytes');
        $this->assertSame(
            $text->getContent(),
            substr($source, $position->startOffset, $position->endOffset - $position->startOffset),
            'the span selects bytes the node does not hold',
        );
    }

    /**
     * INTENDED SURVIVOR, and the reason the fix is narrow: the cell the
     * continuation actually rebuilt stays UNPLACED. Its value is assembled
     * from two authored chunks with the row's closing `|` and the `+` marker
     * between them, so no offset pair selects it - which is what carve-js and
     * carve-rs publish, and what `markup-carve/carve#1393` waives.
     */
    public function testTheJoinedCellItselfStaysUnplaced(): void
    {
        $cells = $this->cells($this->parse("|=a |=b |\n+ cont |\n"));
        $text = $cells[0]->getChildren()[0];

        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('a cont', $text->getContent());
        $this->assertNull($text->getPos(), 'a value spanning the join was given a span it is not a slice of');
    }

    /**
     * INTENDED SURVIVOR: a row with no continuation at all was never affected
     * and must keep placing both cells the way it always did.
     */
    public function testAPlainHeaderRowIsUnaffected(): void
    {
        $source = "|=a |=b |\n";
        $cells = $this->cells($this->parse($source));

        foreach ($cells as $index => $cell) {
            $text = $cell->getChildren()[0];
            $this->assertInstanceOf(Text::class, $text);
            $position = $text->getPos();
            $this->assertNotNull($position, "cell {$index} lost its position");
            $this->assertSame(
                $text->getContent(),
                substr($source, $position->startOffset, $position->endOffset - $position->startOffset),
                "cell {$index} selects bytes it does not hold",
            );
        }
    }
}
