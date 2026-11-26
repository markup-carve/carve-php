<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-112: the `listTable` import option (carve#6771), as the carve-js tests of the same option.
 */
final class ListTableImportTest extends TestCase
{
    public function testRowOfOneCellOfTwoBlocksIsTight(): void
    {
        self::assertSame("::: list-table\n- - x\n\n    y\n:::\n", $this->listTable('<table><tr><td><p>x</p><p>y</p></td></tr></table>')[0]);
    }

    public function testNestedTableOfBlocksIsAListTableOfItsOwn(): void
    {
        $html = '<table><tr><td><table><tr><td><ul><li>n</li></ul></td></tr></table></td><td>a</td></tr></table>';
        self::assertSame(":::: list-table\n- - ::: list-table\n    - - - n\n    :::\n  - a\n::::\n", $this->listTable($html)[0]);
    }

    public function testDropsBodyBoundaryColumnWidthAndGroupAttribute(): void
    {
        [$source, $codes] = $this->listTable('<table><colgroup><col style="width: 50%"></colgroup>'
            . '<tbody class="b"><tr><td><ul><li>a</li></ul></td></tr></tbody><tbody><tr><td>b</td></tr></tbody></table>');
        self::assertSame("::: list-table\n- - - a\n- - b\n:::\n", $source);
        sort($codes);
        self::assertSame(['import-attribute-dropped', 'import-attribute-dropped', 'import-structure-repaired'], $codes);
    }

    public function testDropsCaptionThatIsNotOneParagraph(): void
    {
        [$source, $codes] = $this->listTable('<table><caption><p>a</p><p>b</p></caption><tr><td><ul><li>a</li></ul></td></tr></table>');
        self::assertSame("::: list-table\n- - - a\n:::\n", $source);
        self::assertSame(['import-element-dropped'], $codes);
    }

    public function testFooterHeaderCellOutsideHeaderColumns(): void
    {
        $html = '<table><tbody><tr><td><ul><li>a</li></ul></td></tr></tbody><tfoot><tr><th>f</th></tr></tfoot></table>';
        self::assertSame("{footer-rows=1}\n::: list-table\n- - - a\n- -{header} f\n:::\n", $this->listTable($html)[0]);
    }

    public function testCoveredSlotCountsAsItsHeaderCell(): void
    {
        $html = '<table><tr><th rowspan="2">h</th><td><ul><li>a</li></ul></td></tr><tr><td>b</td></tr></table>';
        self::assertSame("{header-cols=1}\n::: list-table\n- - h\n  - - a\n- - ^\n  - b\n:::\n", $this->listTable($html)[0]);
    }

    public function testTableOfParagraphCellsStaysAPipeTable(): void
    {
        $result = Carve::importHtml('<table><tr><td><p>x</p></td><td>y</td></tr></table>', ['listTable' => true]);
        self::assertSame('table', $result->tree->children[0]->type ?? null);
    }

    public function testOffByDefault(): void
    {
        $result = Carve::importHtml('<table><tr><td><ul><li>a</li></ul></td></tr></table>');
        self::assertSame('table', $result->tree->children[0]->type ?? null);
    }

    public function testHtmlOnly(): void
    {
        $this->expectException(OptionError::class);
        $this->expectExceptionMessage('listTable');
        Carve::importDocument('a', ['from' => 'markdown', 'listTable' => true]);
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function listTable(string $html): array
    {
        $result = Carve::importHtml($html, ['listTable' => true]);
        $back = json_decode(Carve::encodeTree(Carve::parse($result->source)), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(TreeEquality::equal($back, json_decode(Carve::encodeTree($result->tree), true, 512, JSON_THROW_ON_ERROR)));

        return [$result->source, $result->codes()];
    }
}
