<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A list item whose only content is a nested list keeps its nesting level
 * through HtmlToCarve (carve-php#595).
 *
 * The converter used to emit a bare marker line and let the nested list follow
 * after a blank line. That is not a formatting difference: a marker with no
 * content is not a list item in Carve, so `- ` came back as a paragraph reading
 * `-`, the blank line made the list loose, and the nested list dedented to the
 * top level. One nesting level was lost and a stray paragraph gained.
 *
 * These assert on the RE-PARSED HTML rather than on the emitted source, because
 * the defect is a change of document, not of spelling - and the spelling is
 * allowed to differ from the input as long as the tree does not.
 */
class ListTableNestingTest extends TestCase
{
    protected function assertHtmlSurvives(string $carve): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($carve);
        $back = (new HtmlToCarve())->convert($html);

        $this->assertSame(trim($html), trim($converter->convert($back)));
    }

    public function testAListTableKeepsItsOuterItem(): void
    {
        $this->assertHtmlSurvives(
            "::: list-table\n- - Cells with block content\n  - are a Carve construct\n:::\n",
        );
    }

    public function testTheEmittedSourceStartsTheNestedListOnTheMarkerLine(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("- - a\n  - b\n");

        $this->assertStringContainsString('- - a', (new HtmlToCarve())->convert($html));
    }

    public function testThreeLevelsDeep(): void
    {
        $this->assertHtmlSurvives("- - - deep\n");
    }

    public function testAnOrderedOuterItemKeepsItsNestedList(): void
    {
        $this->assertHtmlSurvives("1. - a\n   - b\n");
    }

    public function testSeveralOuterItemsEachKeepTheirNestedList(): void
    {
        $this->assertHtmlSurvives("- - a\n  - b\n- - c\n");
    }

    /**
     * The neighbouring shape: an item with text AND a nested list still puts
     * the text on the marker line and the list beneath it.
     */
    public function testAnItemWithTextAndANestedListIsUnchanged(): void
    {
        $this->assertHtmlSurvives("- text\n  - child\n");
    }
}
