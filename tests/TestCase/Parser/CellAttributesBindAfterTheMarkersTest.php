<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §5 T10: a cell's attribute block binds after its kind and alignment
 * markers, in every cell.
 *
 * `header_cell` had no attributes slot at all, so an attributed header cell was
 * unspellable. The only shape available, `|{#x}=R|`, is ambiguous by
 * construction - an attributed header cell, or a data cell whose content starts
 * with `=` - and this grammar resolves it as the second, so the shape the
 * canonical writer produced for `<th id="x">R</th>` came back as
 * `<td id="x">=R</td>`. Once `=` has committed the cell to header, everything
 * after it is unambiguous, which is what makes the shape expressible.
 *
 * The corpus category 319 pins the rule. This test carries the cases the corpus
 * does not: the controls that must NOT move, the round trip on an attributed
 * header cell, and §5 T9's `scope` spelling, which the rule is what makes
 * grammatical.
 */
class CellAttributesBindAfterTheMarkersTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function boundAfterTheMarkersProvider(): array
    {
        return [
            'a kind marker' => [
                "|={.total} Total |= 99 |\n",
                '<th scope="col" class="total">Total</th>',
            ],
            'a kind marker and an alignment marker' => [
                "|=~{#score} Score |\n",
                '<th scope="col" id="score" style="text-align: center;">Score</th>',
            ],
            'an alignment marker alone' => [
                "|= Item |\n| Pen |>{.num} 9 |\n",
                '<td class="num" style="text-align: right;">9</td>',
            ],
            'no marker at all' => [
                "|{.num} 9 |\n",
                '<td class="num">9</td>',
            ],
        ];
    }

    #[DataProvider('boundAfterTheMarkersProvider')]
    public function testTheBlockBindsAfterTheMarkers(string $source, string $expectedCell): void
    {
        $this->assertStringContainsString($expectedCell, $this->html($source));
    }

    /**
     * CONTROL. The RETIRED order does not also work. `<` is no longer in a
     * marker position, so it is literal content and the cell is not aligned -
     * the one released spelling this rule reinterprets, which is why it ships
     * with `table-cell-attribute-before-marker` rather than a rewrite.
     */
    public function testTheRetiredOrderIsContent(): void
    {
        $html = $this->html("|{#x}< content |\n");

        $this->assertStringContainsString('<td id="x">&lt; content</td>', $html);
        $this->assertStringNotContainsString('text-align', $html);
    }

    /**
     * CONTROL. The ambiguous shape stays what it always was: a data cell whose
     * content begins with `=`. Reading it as an attributed header cell is the
     * alternative T10 rejects.
     */
    public function testTheAmbiguousShapeIsStillADataCell(): void
    {
        $this->assertStringContainsString('<td id="x">=R</td>', $this->html("|{#x}=R|\n"));
    }

    /**
     * CONTROL. The marker run is only consumed where a block actually follows
     * it, so a lone `<` or `^` is still a span marker rather than an alignment
     * sigil on an empty cell.
     */
    public function testALoneSpanMarkerIsUnaffected(): void
    {
        $this->assertStringContainsString('colspan="2"', $this->html("| a |<|\n"));
        $this->assertStringContainsString('rowspan="2"', $this->html("| a |\n|^|\n"));
    }

    /**
     * CONTROL. A SPACE in front of the brace is ordinary content in every
     * position, marker or not.
     */
    public function testASpacedBraceIsContent(): void
    {
        $this->assertStringContainsString('<td>{.x} d</td>', $this->html("| {.x} d |\n"));
        $this->assertStringContainsString('<th scope="col">{.x} h</th>', $this->html("|= {.x} h |\n"));
    }

    /**
     * Row attributes do not move: they still glue to the row's CLOSING pipe,
     * and a cell block in the same row is read separately.
     */
    public function testRowAttributesAreUnmoved(): void
    {
        $this->assertStringContainsString(
            '<tr class="win"><td class="num" style="text-align: right;">9</td></tr>',
            $this->html("|>{.num} 9 |{.win}\n"),
        );
    }

    /**
     * PART 11 §1 on the shape that broke it. The writer emits the markers
     * first, so an attributed header cell parses back to the node that was
     * written instead of to a data cell whose content starts with `=`.
     */
    public function testTheRoundTripHoldsForAnAttributedHeaderCell(): void
    {
        $source = "|={#x} R |\n| 1 |\n";

        $this->assertSame("|={#x} R |\n| 1 |\n", $this->fmt($source));
        $this->assertStringContainsString('<th scope="col" id="x">R</th>', $this->html($source));
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        $this->assertSame($this->fmt($source), $this->fmt($this->fmt($source)));
    }

    /**
     * The writer reaches the same shape from an AST it did not parse from Carve
     * source, which is where the failure was found: `fmt` used to emit
     * `|{#x}=R|` for this cell.
     */
    public function testTheWriterNeverEmitsTheBlockAheadOfTheMarker(): void
    {
        $written = $this->fmt("|={#x} R |\n| 1 |\n");

        $this->assertStringNotContainsString('|{#x}=', $written);
        $this->assertStringContainsString('|={#x} R |', $written);
    }

    /**
     * §5 T9 documents `|={scope="colgroup"} a |` as the way to reach `colgroup`
     * and `rowgroup`, which have no marker spelling. That sentence was not
     * expressible under the retired productions - the braces rendered as text.
     */
    public function testAnAuthoredScopeReachesTheHeaderCell(): void
    {
        $html = $this->html("|={scope=\"colgroup\"} a |\n");

        $this->assertStringContainsString('<th scope="colgroup">a</th>', $html);
        $this->assertStringNotContainsString('scope="col"', $html);
    }

    public function testADuplicateVerticalAxisFallsBackAsOneLiteralRun(): void
    {
        $html = $this->html("|=^^ Note |= Plain |\n| a | b |\n");

        $this->assertStringContainsString('<th scope="col">^^ Note</th>', $html);
        $this->assertStringNotContainsString('vertical-align', $html);
    }
}
