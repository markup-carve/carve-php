<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A column's alignment is published on the header cells, not on every cell.
 *
 * Alignment is declared ONCE, in the delimiter row, and it is a property of the
 * COLUMN. Publishing the resolved value on every body cell states as authored,
 * per cell, something the source says once for the whole column - and it leaves
 * a consumer unable to tell an inherited alignment from a per-cell one, which
 * is the distinction it would need if per-cell alignment ever becomes
 * expressible.
 *
 * The header row is where the delimiter row's columns land one-to-one, so that
 * is the compact encoding the wire uses (carve#784).
 *
 * The internal node keeps its alignment either way: the HTML renderer needs it
 * to emit `style="text-align:…"` on body cells, and that is unchanged here -
 * only what reaches the wire moves.
 */
class TableCellAlignmentIsAColumnPropertyTest extends TestCase
{
    /**
     * @return array<int, array{header: bool, align: string|null}>
     */
    protected function cells(string $source): array
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'table_cell') {
                $found[] = ['header' => $node['header'], 'align' => $node['align'] ?? null];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testAHeaderCellPublishesItsColumnAlignment(): void
    {
        $cells = $this->cells("| a | b |\n|:---|---:|\n| 1 | 2 |\n");

        $this->assertSame(['header' => true, 'align' => 'left'], $cells[0]);
        $this->assertSame(['header' => true, 'align' => 'right'], $cells[1]);
    }

    public function testABodyCellDoesNot(): void
    {
        $cells = $this->cells("| a | b |\n|:---|---:|\n| 1 | 2 |\n");

        $this->assertSame(['header' => false, 'align' => null], $cells[2]);
        $this->assertSame(['header' => false, 'align' => null], $cells[3]);
    }

    public function testTheRenderStillAlignsBodyCells(): void
    {
        // The control. The node keeps its alignment; only the WIRE changes. If
        // this stops holding, the field was removed from the wrong layer.
        $html = (new CarveConverter())->convert("| a | b |\n|:---|---:|\n| 1 | 2 |\n");

        $this->assertStringContainsString('text-align: left', $html);
        $this->assertStringContainsString('text-align: right', $html);
    }

    public function testAnUnalignedTablePublishesNoAlignAnywhere(): void
    {
        foreach ($this->cells("| a |\n|---|\n| 1 |\n") as $cell) {
            $this->assertNull($cell['align']);
        }
    }
}
