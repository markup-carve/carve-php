<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A table holding rows or cells directly reads back with the `tbody` HTML5
 * implies, on either parser.
 *
 * libxml keeps them where they were written, so on PHP 8.3 a diagnostic path
 * lost the `tbody` step carve-js and carve-rs report and the raw-kept bytes came
 * back a level shallower. Both arms are asserted, because the runtime picks the
 * parser and the regression only ever appeared on the one this process may not
 * have (markup-carve/carve#2485).
 */
class ABareTableRowGainsTheImpliedRowGroupTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tables(): array
    {
        return [
            'a bare row' => ['<table><tr><td>a</td></tr></table>', 'table(tbody(tr(td)))'],
            'two bare rows share one group' => [
                '<table><tr><td>a</td></tr><tr><td>b</td></tr></table>',
                'table(tbody(tr(td),tr(td)))',
            ],
            'a bare cell implies its row too' => ['<table><td>a</td></table>', 'table(tbody(tr(td)))'],
            'bare cells share one implied row' => [
                '<table><td>a</td><td>b</td></table>',
                'table(tbody(tr(td,td)))',
            ],
            'an explicit row ends the implied one' => [
                '<table><td>a</td><tr><td>b</td></tr></table>',
                'table(tbody(tr(td),tr(td)))',
            ],
            'a bare cell after a row starts another' => [
                '<table><tr><td>a</td></tr><td>b</td></table>',
                'table(tbody(tr(td),tr(td)))',
            ],
            'a header group keeps its own rows' => [
                '<table><thead><tr><th>h</th></tr></thead><tr><td>a</td></tr></table>',
                'table(thead(tr(th)),tbody(tr(td)))',
            ],
            'a group between two bare rows separates them' => [
                '<table><tr><td>a</td></tr><thead><tr><th>h</th></tr></thead><tr><td>b</td></tr></table>',
                'table(tbody(tr(td)),thead(tr(th)),tbody(tr(td)))',
            ],
            'an explicit body does not absorb the row after it' => [
                '<table><tbody><tr><td>a</td></tr></tbody><tr><td>b</td></tr></table>',
                'table(tbody(tr(td)),tbody(tr(td)))',
            ],
            'a caption stays outside the group' => [
                '<table><caption>c</caption><tr><td>a</td></tr></table>',
                'table(caption,tbody(tr(td)))',
            ],
            'a column group stays outside it' => [
                '<table><colgroup><col></colgroup><tr><td>a</td></tr></table>',
                'table(colgroup(col),tbody(tr(td)))',
            ],
            'a nested table gains its own' => [
                '<table><tr><td><table><tr><td>a</td></tr></table></td></tr></table>',
                'table(tbody(tr(td(table(tbody(tr(td)))))))',
            ],
        ];
    }

    #[DataProvider('tables')]
    public function testTheParserTheRuntimePicksInsertsTheGroup(string $html, string $shape): void
    {
        self::assertSame($shape, $this->tableShape(HtmlDomLoader::load($html)), $html);
    }

    /**
     * The libxml arm, reached directly so it is covered on a PHP that has the
     * HTML5 parser as well. `load()` runs this same normalization on the tree
     * `loadHTML()` returns.
     */
    #[DataProvider('tables')]
    public function testTheLibxmlArmInsertsTheGroup(string $html, string $shape): void
    {
        $document = new DOMDocument();
        $document->encoding = 'UTF-8';
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        HtmlDomLoader::impliedTableBodies($document);

        self::assertSame($shape, $this->tableShape($document), $html);
    }

    /**
     * A table with nothing to move keeps the tree it was written with, so the
     * normalization cannot be passing above by rewriting every table.
     */
    public function testAFullySpelledTableIsUntouched(): void
    {
        $html = '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>a</td></tr></tbody><tfoot><tr><td>f</td></tr></tfoot></table>';
        $expected = 'table(thead(tr(th)),tbody(tr(td)),tfoot(tr(td)))';

        self::assertSame($expected, $this->tableShape(HtmlDomLoader::load($html)));
    }

    /**
     * What the cross-engine import report compares: the row's path names the
     * group, so carve-php reads the same as the other two engines.
     */
    public function testTheReportPathNamesTheImpliedGroup(): void
    {
        $html = '<form><table><tr><td align="right" style="text-align:left">text</td></tr></table></form>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $paths = array_values(array_filter(array_map(
            static fn (array $diagnostic): string => $diagnostic['path'] ?? '',
            $result->report()['diagnostics'],
        ), static fn (string $path): bool => str_contains($path, 'td[')));

        self::assertSame([
            '/form[1]/table[1]/tbody[1]/tr[1]/td[1]',
            '/form[1]/table[1]/tbody[1]/tr[1]/td[1]',
        ], $paths);
        self::assertStringContainsString('<table><tbody><tr><td', $result->value);
    }

    /**
     * The outermost table's shape. Anchored there because the HTML5 parser
     * builds a whole document around a fragment and libxml does not, and the
     * wrapper is not what these cases are about.
     */
    protected function tableShape(DOMDocument $document): string
    {
        $table = $document->getElementsByTagName('table')->item(0);
        self::assertInstanceOf(DOMElement::class, $table);

        return 'table(' . $this->shape($table) . ')';
    }

    /**
     * Element names only, nested, so the assertion reads the tree shape without
     * depending on how either parser serializes a void element or whitespace.
     */
    protected function shape(DOMNode $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $inner = $this->shape($child);
            $name = strtolower(HtmlDomLoader::elementName($child));
            $parts[] = $inner === '' ? $name : $name . '(' . $inner . ')';
        }

        return implode(',', $parts);
    }
}
