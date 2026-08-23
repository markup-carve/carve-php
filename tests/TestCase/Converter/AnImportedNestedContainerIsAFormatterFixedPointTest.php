<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `docs/html-import.md` gives the importer no freedom about the fence width:
 * "an importer emits the source `carve fmt` emits", and of the shared fixtures,
 * "every `expected.crv` here is also a fixed point of `carve fmt` in all three
 * engines".
 *
 * A colon fence closes on an EXACT length match (grammar PART 9 §12), so
 * "longer-outer documents and longer-inner ones both parse" and the direction
 * is a WRITER's choice - which is exactly why it has to be pinned rather than
 * left to whichever end of the tree the writer happens to work from. `carve
 * fmt` writes the INWARD-WIDENING form, and this engine's importer wrote the
 * other one at every nesting depth (markup-carve/carve-php#1583).
 *
 * DEPTH IS THE POINT, not the tab set. The old width came from scanning the
 * already-written body for colon-only lines, which is bottom-up and can only
 * widen outward, so the inversion reached every container the importer builds
 * and every depth it builds them at. A test that covered `tabs`/`tabs-panel`
 * alone would be a check that cannot fail for the third level.
 */
class AnImportedNestedContainerIsAFormatterFixedPointTest extends TestCase
{
    /**
     * HTML whose import must be a `carve fmt` fixed point.
     *
     * @return array<string, array{0: string}>
     */
    public static function containerHtmlProvider(): array
    {
        return [
            'a named container' => ['<div class="tabs"><p>a</p></div>'],
            'a callout' => ['<aside class="admonition note" aria-label="Note"><p>body</p></aside>'],
            'a panel inside a tab set' => ['<div class="tabs"><div class="tabs-panel"><p>a</p></div></div>'],
            'three named containers' => ['<div class="outer"><div class="mid"><div class="inner"><p>a</p></div></div></div>'],
            'four named containers' => ['<div class="a"><div class="b"><div class="c"><div class="d"><p>x</p></div></div></div></div>'],
            'a callout inside a container' => ['<div class="tabs"><aside class="admonition note" aria-label="Note"><p>a</p></aside></div>'],
            'a container inside a callout' => ['<aside class="admonition note" aria-label="Note"><div class="tabs"><p>a</p></div></aside>'],
            'a callout inside a callout' => [
                '<aside class="admonition note" aria-label="Note">'
                . '<aside class="admonition tip" aria-label="Tip"><p>a</p></aside></aside>',
            ],
            'a details inside a details' => [
                '<details><summary>T</summary><details><summary>U</summary><p>a</p></details></details>',
            ],
            'a line block' => ['<div class="line-block">a<br>b</div>'],
            'a line block inside a container' => ['<div class="tabs"><div class="line-block">a<br>b</div></div>'],
            'a closer-shaped verse line' => ['<div class="line-block">:::<br>b</div>'],
            'a closer-shaped verse line inside a container' => [
                '<div class="tabs"><div class="line-block">:::<br>b</div></div>',
            ],
        ];
    }

    #[DataProvider('containerHtmlProvider')]
    public function testAnImportedContainerIsAFormatterFixedPoint(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * The `list-table` fence is a container the importer builds too, and it is
     * reached from a table cell rather than from an element the container
     * walk above hands down - so it is the one that answers whether the width
     * comes from the writer's DEPTH or from the call that happened to write it.
     *
     * @return array<string, array{0: string}>
     */
    public static function listTableHtmlProvider(): array
    {
        return [
            'at the top level' => ['<table><tr><td><ul><li>a</li></ul></td></tr></table>'],
            'inside a container' => [
                '<div class="tabs"><table><tr><td><ul><li>a</li></ul></td></tr></table></div>',
            ],
            'two containers deep' => [
                '<div class="a"><div class="b"><table><tr><td><ul><li>a</li></ul></td></tr></table></div></div>',
            ],
        ];
    }

    #[DataProvider('listTableHtmlProvider')]
    public function testAnImportedListTableIsAFormatterFixedPoint(string $html): void
    {
        $imported = (new HtmlToCarve(listTableForBlockCells: true))->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAListTableInsideAContainerTakesTheInnerWidth(): void
    {
        $imported = (new HtmlToCarve(listTableForBlockCells: true))->convert(
            '<div class="tabs"><table><tr><td><ul><li>a</li></ul></td></tr></table></div>',
        );

        $this->assertSame("::: tabs\n:::: list-table\n- - - a\n::::\n:::\n", $imported);
    }

    /**
     * The ticket's own document, spelled out: `::::`/`:::` came back as
     * `:::`/`::::` from the formatter, so neither writer's answer was the
     * other's.
     */
    public function testATabSetAndItsPanelWidenInward(): void
    {
        $imported = (new HtmlToCarve())->convert('<div class="tabs"><div class="tabs-panel"><p>a</p></div></div>');

        $this->assertSame("::: tabs\n:::: tabs-panel\na\n::::\n:::\n", $imported);
    }

    /**
     * THE THIRD LEVEL, which is the level a `tabs`/`tabs-panel` fix would not
     * have reached: the width is `3 + depth` rather than one more than whatever
     * the body turned out to hold.
     */
    public function testTheWidthKeepsWideningInwardBelowTheSecondLevel(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<div class="outer"><div class="mid"><div class="inner"><p>a</p></div></div></div>',
        );

        $this->assertSame("::: outer\n:::: mid\n::::: inner\na\n:::::\n::::\n:::\n", $imported);
    }

    /**
     * A verse line that is itself a bare colon run used to be made harmless by
     * widening the block's own fence around it - which is not a width question
     * at all, and left the source outside the formatter's image either way. The
     * formatter escapes it, at whatever width the container sits at.
     */
    public function testACloserShapedVerseLineIsEscapedRatherThanWidenedAround(): void
    {
        $imported = (new HtmlToCarve())->convert('<div class="line-block">:::<br>b</div>');

        $this->assertSame("::: |\n\\:::\nb\n:::\n", $imported);
        $this->assertSame(
            "<div class=\"line-block\">\n  <p>:::<br>\nb</p>\n</div>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * The construct survives the width change: an imported container still
     * renders the HTML it was imported from.
     */
    public function testTheNestedContainerStillRendersTheHtmlItCameFrom(): void
    {
        $html = '<div class="tabs"><div class="tabs-panel"><p>a</p></div></div>';
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            "<div class=\"tabs\">\n  <div class=\"tabs-panel\">\n    <p>a</p>\n  </div>\n</div>\n",
            (new CarveConverter())->convert($imported),
        );
    }
}
