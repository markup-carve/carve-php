<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A table cell's published span covers the cell, attribute block included.
 *
 * `parseTableCellsWithAttributes()` advances a cell's offset past its `{...}`
 * block so the CONTENT can be placed at its own offset, and `cellExtentSpan()`
 * then added the cell's full `rawLength` to that advanced offset. The span slid
 * right by exactly the width of the attribute block, which put its end past the
 * end of the line and made it overlap the next cell:
 *
 *     |{.highlight} Total | 99 |
 *
 * carve-js and carve-rs both publish columns 2-21 for the first cell - the cell
 * as written, brace to the padding before the closing pipe. This engine
 * published 15-34 on a 26-column line, and the spec repo's AST conformance run
 * reported it as `sibling spans overlap ... "table_cell" starts at 21, inside
 * "table_cell" which ends at 33` (corpus 99-table-cell-attributes).
 *
 * A position that leaves the line is not a cosmetic error: an editor mapping a
 * click to a node, or a diagnostic pointing at a cell, lands somewhere that
 * does not exist.
 */
class TableCellExtentSpanTest extends TestCase
{
    /**
     * @return array<int, array{startColumn: int, endColumn: int}>
     */
    protected function cellSpans(string $source): array
    {
        $tree = (new AstCodec())->encode(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source),
        );
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'table_cell' && isset($node['pos'])) {
                $found[] = [
                    'startColumn' => $node['pos']['startColumn'],
                    'endColumn' => $node['pos']['endColumn'],
                ];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testAnAttributedCellSpanStartsAtTheBrace(): void
    {
        // Columns carve-js and carve-rs both publish for this row.
        $spans = $this->cellSpans("|{.highlight} Total | 99 |\n|---|---|\n| a | b |\n");

        $this->assertSame(['startColumn' => 2, 'endColumn' => 21], $spans[0]);
    }

    public function testAnAttributedCellSpanStaysInsideItsLine(): void
    {
        $source = "|{.highlight} Total | 99 |\n|---|---|\n| a | b |\n";
        $lineLength = strlen(explode("\n", $source)[0]);
        $spans = $this->cellSpans($source);

        $this->assertLessThanOrEqual(
            $lineLength + 1,
            $spans[0]['endColumn'],
            'the span ends past the end of the line it is on',
        );
    }

    public function testSiblingCellSpansDoNotOverlap(): void
    {
        // The failure the spec repo's conformance run reports: the first cell's
        // end lands inside the second cell.
        $spans = $this->cellSpans("|{.highlight} Total | 99 |\n|---|---|\n| a | b |\n");

        $this->assertLessThanOrEqual($spans[1]['startColumn'], $spans[0]['endColumn']);
    }

    public function testAPlainCellIsUnaffected(): void
    {
        $spans = $this->cellSpans("| a | b |\n|---|---|\n| c | d |\n");

        $this->assertSame(['startColumn' => 2, 'endColumn' => 5], $spans[0]);
    }

    public function testTheContentInsideAnAttributedCellKeepsItsOwnOffset(): void
    {
        // The offset advance this fix works around exists so the TEXT lands
        // where it was written. That must survive: `Total` starts at column 15.
        $tree = (new AstCodec())->encode(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))
                ->parse("|{.highlight} Total | 99 |\n|---|---|\n| a | b |\n"),
        );
        $text = null;
        $walk = function (mixed $node) use (&$walk, &$text): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'text' && ($node['value'] ?? null) === 'Total') {
                $text = $node['pos'] ?? null;
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        $this->assertNotNull($text, 'the Total text node has no position');
        $this->assertSame(15, $text['startColumn']);
    }
}
