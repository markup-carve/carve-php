<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two markers that reach the same column are siblings, however they got there.
 *
 * PART 9 §24 C1 makes indentation a COLUMN claim: a space advances one column,
 * a tab advances to the next multiple of 4. So `<SPACE><TAB>` and four spaces
 * both put a marker at column 4, and two markers there are one list.
 *
 * A third `<ul>` opened between them instead (carve-php#890). The cause is one
 * function: dedenting to a content column consumed a TAB WHOLE even when the
 * tab crossed that column, so the columns past the boundary were lost. One
 * marker arrived at the nested parse still indented and the other flush, and
 * two different columns are two different lists.
 */
class SiblingMarkersAtAColumnAreOneListTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function sameColumnPairs(): array
    {
        return [
            'four spaces then space-tab' => ["- a\n    - b\n \t- c\n"],
            'space-tab then four spaces' => ["- a\n \t- b\n    - c\n"],
            'four spaces then tab' => ["- a\n    - b\n\t- c\n"],
            'tab then four spaces' => ["- a\n\t- b\n    - c\n"],
        ];
    }

    #[DataProvider('sameColumnPairs')]
    public function testMarkersAtTheSameColumnAreOneList(string $source): void
    {
        // Counting `<ul>` opens: 2 is one nested list, 3 is the extra one.
        $this->assertSame(2, substr_count($this->html($source), '<ul>'), $this->html($source));
    }

    public function testUniformIndentationIsUnchanged(): void
    {
        // The control that already passed, kept because it is what a fix could
        // break: the same shape with matching whitespace.
        $this->assertSame(2, substr_count($this->html("- a\n    - b\n    - c\n"), '<ul>'));
        $this->assertSame(2, substr_count($this->html("- a\n\t- b\n\t- c\n"), '<ul>'));
    }

    public function testAStraddlingTabKeepsTheColumnsPastTheBoundary(): void
    {
        // The unit underneath, stated directly: asking for 2 columns of a
        // `<SPACE><TAB>` indent leaves 2 columns behind, not none. Without
        // this the line arrives flush and lands in a different list.
        $this->assertSame('  - c', IndentationHelper::stripLeadingColumns(" \t- c", 2));
        $this->assertSame('  - c', IndentationHelper::stripLeadingColumns("\t- c", 2));
    }

    public function testStrippingAWholeTabIsStillExact(): void
    {
        // The boundary: when the amount lands ON a tab stop nothing is left
        // over, which is the case every uniform document takes.
        $this->assertSame('- c', IndentationHelper::stripLeadingColumns("\t- c", 4));
        $this->assertSame('- c', IndentationHelper::stripLeadingColumns(" \t- c", 4));
        $this->assertSame('  - c', IndentationHelper::stripLeadingColumns('    - c', 2));
    }
}
