<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A raw region reaching an inline-only slot - a table cell, a caption - keeps
 * its bytes instead of projecting to nothing (markup-carve/carve-php#2362).
 *
 * `roundtrip` keeps `address`, `fieldset`, `form` and `hgroup` as a raw BLOCK,
 * and a cell flattens its blocks to inlines. The flatten walked `children`,
 * which a raw node has none of, so the element's whole content left the document
 * in silence - and a cell it emptied took its row with it, so a one-cell table
 * came back as an empty document while the report said the element had been
 * unwrapped.
 */
class ARawRegionInATableCellKeepsItsBytesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cells(): array
    {
        return [
            'the only cell of a table' => [
                '<table><tr><td><form>f</form></td></tr></table>',
                '| `<form>f</form>`{=html} |',
                'f',
            ],
            'one cell of two' => [
                '<table><tr><td><form>f</form></td><td>b</td></tr></table>',
                '| `<form>f</form>`{=html} | b |',
                'f',
            ],
            'a header cell' => [
                '<table><thead><tr><th><form>f</form></th></tr></thead><tbody><tr><td>z</td></tr></tbody></table>',
                "|= `<form>f</form>`{=html} |\n| z |",
                'f',
            ],
            'a fieldset' => [
                '<table><tr><td><fieldset>f</fieldset></td></tr></table>',
                '| `<fieldset>f</fieldset>`{=html} |',
                'f',
            ],
            'an address' => [
                '<table><tr><td><address>f</address></td></tr></table>',
                '| `<address>f</address>`{=html} |',
                'f',
            ],
            'an hgroup' => [
                '<table><tr><td><hgroup>f</hgroup></td></tr></table>',
                '| `<hgroup>f</hgroup>`{=html} |',
                'f',
            ],
            'blocks inside the form' => [
                '<table><tr><td><form><p>f</p><p>g</p></form></td></tr></table>',
                '| `<form><p>f</p><p>g</p></form>`{=html} |',
                'f',
            ],
            "a table's caption" => [
                '<table><caption><form>f</form></caption><tr><td>z</td></tr></table>',
                "| z |\n^ `<form>f</form>`{=html}",
                'f',
            ],
        ];
    }

    #[DataProvider('cells')]
    public function testTheCellKeepsTheRegion(string $html, string $carve, string $text): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $rendered = (new CarveConverter())->convert($result->value);
        $this->assertStringContainsString('<table>', $rendered, 'the table went with the cell');
        $this->assertStringContainsString($text, strip_tags($rendered));
        $this->assertContains(
            'raw-preserved',
            array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics),
            'the report no longer says the bytes are there',
        );
    }

    /**
     * The row over the kept bytes has to stay true of them: an event handler
     * inside a region the cell keeps is LIVE, and the report that called it
     * dropped is the reading nobody can act on (markup-carve/carve#2261).
     */
    public function testAHandlerInTheKeptRegionIsReportedPreserved(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<table><tr><td><form onsubmit="x()">f</form></td></tr></table>');
        $this->assertStringContainsString('onsubmit="x()"', $result->value);
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics);
        $this->assertContains('attribute-preserved', $codes);
        $this->assertNotContains('attribute-dropped', $codes);
    }

    /**
     * A ROW is one line, so a region of several lines stays out of it: the row
     * would end at the first newline and the table with it. Joining the lines is
     * not the way in either - that changes the bytes the raw-keep report reads
     * back, and the live handler above would come back as an
     * `attribute-dropped` row. So the cell empties, which is the loss this arm
     * inherited rather than one it added, and the TABLE survives.
     */
    public function testAMultiLineRegionStaysOutOfARow(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport("<table><tr><td><form>\nf\n</form></td><td>b</td></tr></table>");
        $this->assertSame('| | b |', rtrim($result->value, "\n"));
        $rendered = (new CarveConverter())->convert($result->value);
        $this->assertStringContainsString('<table>', $rendered, 'the row ended at the newline');
        $this->assertStringContainsString('<td>b</td>', $rendered);
    }

    /**
     * A CAPTION carries a region of several lines, so it keeps one: the slot is
     * not a row, nothing ends at the newline, and the bytes reach the output
     * unchanged - which is what keeps the report's rows true of them.
     *
     * @return array<string, array{string, string}>
     */
    public static function multiLineCaptions(): array
    {
        return [
            "a table's caption" => [
                "<table><caption><form>\nf\n</form></caption><tr><td>z</td></tr></table>",
                "| z |\n^ `<form>\nf\n</form>`{=html}",
            ],
            'a figcaption' => [
                "<figure><img src=\"i.png\" alt=\"a\"><figcaption><form>\nf\n</form></figcaption></figure>",
                "![a](i.png)\n^ `<form>\nf\n</form>`{=html}",
            ],
        ];
    }

    #[DataProvider('multiLineCaptions')]
    public function testACaptionKeepsAMultiLineRegion(string $html, string $carve): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $rendered = (new CarveConverter())->convert($result->value);
        $this->assertMatchesRegularExpression('/<(caption|figcaption)>/', $rendered);
        $this->assertStringContainsString('f', strip_tags($rendered));
    }

    /**
     * The controls: the modes that do NOT keep those tags raw still unwrap them,
     * and a raw region outside an inline-only slot is still a raw BLOCK.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function untouched(): array
    {
        return [
            'safe unwraps the form' => ['safe', '<table><tr><td><form>f</form></td></tr></table>', '| f |'],
            'semantic unwraps the form' => ['semantic', '<table><tr><td><form>f</form></td></tr></table>', '| f |'],
            'a form outside a cell' => ['roundtrip', '<div><form>f</form></div>', "```=html\n<form>f</form>\n```"],
            'a form beside a table' => ['roundtrip', '<form>f</form><table><tr><td>z</td></tr></table>', "```=html\n<form>f</form>\n```\n\n| z |"],
        ];
    }

    #[DataProvider('untouched')]
    public function testTheArmReachesNothingElse(string $mode, string $html, string $carve): void
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $this->assertStringContainsString('f', strip_tags((new CarveConverter())->convert($result->value)));
    }

    /**
     * What #2362 asked about and what stays: a cell whose content the importer
     * cannot place is blank, a row of only blank cells is not a table row
     * (markup-carve/carve#1954), and a table of no rows is nothing. The report
     * says so, and this pins the OUTPUT rather than the row.
     */
    public function testACellEmptiedOfEverythingStillTakesItsRow(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td><form><input></form></td></tr></table>');
        $this->assertSame('', trim($result->value));
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics);
        $this->assertSame(['element-dropped', 'element-dropped'], $codes);
        // A second cell with content keeps the table, so the drop is the blank
        // ROW's and not the unplaceable cell's.
        $kept = (new HtmlToCarve())->convertWithReport('<table><tr><td><form><input></form></td><td>b</td></tr></table>');
        $this->assertSame('| | b |', rtrim($kept->value, "\n"));
    }
}
