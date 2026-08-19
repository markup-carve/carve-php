<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The `path` of an HTML import diagnostic.
 *
 * It is a human-readable locator, engine-defined and deliberately NOT an XPath
 * expression - `/p[1]/kbd[11]` is well-formed XPath that selects nothing, and
 * that is understood rather than accidental. Three rules make the string, and
 * each one is asserted on its own below so that fixing two of them and missing
 * the third cannot pass:
 *
 * 1. ROOTING. The path starts at the top level of the fragment the importer was
 *    handed. The `<div>` wrapped around a fragment to give libxml a single root
 *    is the importer's own, and an authored `<html>`/`<head>`/`<body>` is a
 *    shape a fragment parse never builds; neither appears.
 * 2. INDEX BASIS. `[n]` is the position among ALL of the parent's child nodes,
 *    text included - not among its element children.
 * 3. TRAVERSAL. The path names the traversal the conversion performs, not the
 *    parsed tree: a table's rows are flattened out of `<thead>`/`<tbody>` and
 *    numbered across the whole table, and a list's items are numbered among the
 *    items.
 *
 * The rules are carve-js's, adopted for all three engines
 * (`markup-carve/carve#1257`).
 */
class AnImportPathNamesTheFragmentTest extends TestCase
{
    /**
     * @param string $html
     *
     * @return list<string>
     */
    protected function paths(string $html): array
    {
        $diagnostics = (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'];

        return array_map(fn (array $diagnostic): string => $diagnostic['path'] ?? '', $diagnostics);
    }

    // ---- 1. Rooting -------------------------------------------------------

    public function testAFragmentsTopLevelElementIsTheFirstStepOfThePath(): void
    {
        $this->assertSame(['/p[1]'], $this->paths('<p onclick="x()">top level</p>'));
    }

    public function testAnAuthoredDivIsStillNamed(): void
    {
        // The wrapper that goes is the synthetic one. A `<div>` the source
        // actually wrote is a step like any other, so the two cases must not be
        // collapsed into "strip one leading div".
        $this->assertSame(['/div[1]/p[1]'], $this->paths('<div><p onclick="x()">wrapped</p></div>'));
    }

    public function testADocumentsHtmlAndBodyAreNotNamedEither(): void
    {
        $this->assertSame(
            ['/p[1]'],
            $this->paths('<html><body><p onclick="x()">full doc</p></body></html>'),
        );
    }

    public function testAHeadAndABodyNumberIntoOneSequence(): void
    {
        // A fragment parse of the same document puts the head's children and
        // the body's children in one run, so `<title>` takes the first number
        // and the paragraph after it the second.
        $this->assertSame(
            ['/title[1]', '/p[2]'],
            $this->paths('<html><head><title>t</title></head><body><p onclick="x()">after</p></body></html>'),
        );
    }

    public function testTheDocumentElementsStillReportTheirOwnLosses(): void
    {
        // The one carve-out. `<body onload=...>` loses its handler in the
        // conversion, and a diagnostic about the `<body>` has to name the
        // `<body>`, so the element that never appears in a descendant's path
        // still appears in its own. Staying silent to match the fragment rule
        // would drop a loss this importer really makes.
        $this->assertSame(['/html[1]'], $this->paths('<html onclick="x()"><body><p>x</p></body></html>'));
        $this->assertSame(['/body[1]'], $this->paths('<body onclick="x()"><p>x</p></body>'));
        $this->assertSame(
            ['/html[1]/body[1]'],
            $this->paths('<html><body onclick="x()"><p>x</p></body></html>'),
        );
    }

    // ---- 2. Index basis ---------------------------------------------------

    public function testALeadingTextNodeTakesTheFirstNumber(): void
    {
        // The clearest view of the basis: the paragraph is the 2nd child node
        // and the 1st element child, and the path says 2.
        $this->assertSame(['/p[2]'], $this->paths('lead text<p onclick="x()">after text</p>'));
    }

    public function testATextNodeInsideAParagraphTakesANumberToo(): void
    {
        $this->assertSame(['/p[1]/kbd[2]'], $this->paths('<p>a <kbd onclick="x()">Esc</kbd></p>'));
    }

    public function testEveryChildNodeCountsNotOnlyTheOnesBefore(): void
    {
        // Six elements separated by five text nodes: the last `<kbd>` is the
        // 6th element and the 11th node.
        $html = '<p><abbr title="y">A</abbr> <kbd>Tab</kbd> <abbr title="a">S</abbr>'
            . ' <abbr title="">E</abbr> <time datetime="">T</time> <kbd onclick="x()">Esc</kbd></p>';

        $this->assertSame(['/p[1]/kbd[11]'], $this->paths($html));
    }

    public function testABlockAfterWhitespaceIsNotTheFirstChild(): void
    {
        $this->assertSame(['/blockquote[1]/p[2]'], $this->paths("<blockquote>\n<p onclick=\"x()\">q</p>\n</blockquote>"));
    }

    // ---- 3. Traversal -----------------------------------------------------

    public function testARowGroupNeverReachesACellsPath(): void
    {
        // The `<td>` is the 1st cell of the 2nd row OF THE TABLE. Naming the
        // DOM instead would give `/table[1]/tbody[2]/tr[1]/td[1]`, which is a
        // container the Carve output does not have.
        $html = '<table><thead><tr><th>h</th></tr></thead>'
            . '<tbody><tr><td onclick="x()">c</td></tr></tbody></table>';

        $this->assertSame(['/table[1]/tr[2]/td[1]'], $this->paths($html));
    }

    public function testRowsAreNumberedInDocumentOrderAcrossSections(): void
    {
        // A `<tfoot>` written between the head and the body keeps the number
        // its position gives it, rather than the one the rendered order would.
        $html = '<table><thead><tr><th>h</th></tr></thead>'
            . '<tfoot><tr><td onclick="x()">f</td></tr></tfoot>'
            . '<tbody><tr><td>b</td></tr></tbody></table>';

        $this->assertContains('/table[1]/tr[2]/td[1]', $this->paths($html));
    }

    public function testASectionIsStillNamedWhereItSits(): void
    {
        // The flattening is about the rows. A `<tbody>` carrying attributes of
        // its own has nowhere else to be reported, and it is the 2nd child node
        // of the table.
        $html = '<table><thead><tr><th>h</th></tr></thead>'
            . '<tbody onclick="x()"><tr><td>c</td></tr></tbody></table>';

        $this->assertSame(['/table[1]/tbody[2]'], $this->paths($html));
    }

    public function testACellIsNumberedAmongTheCellsOfItsRow(): void
    {
        // Whitespace between the cells does not move the second one to
        // `td[4]`: a row is read as its cells.
        $html = "<table><thead><tr><th>h</th></tr></thead><tbody>\n<tr>\n"
            . "<td>a</td>\n<td onclick=\"x()\">b</td>\n</tr>\n</tbody></table>";

        $this->assertSame(['/table[1]/tr[2]/td[2]'], $this->paths($html));
    }

    public function testAListItemIsNumberedAmongTheItems(): void
    {
        // Same shape as the row: the newlines between the items are child nodes
        // of the `<ul>`, and the second item is still `li[2]`.
        $this->assertSame(
            ['/ul[1]/li[2]'],
            $this->paths("<ul>\n<li>a</li>\n<li onclick=\"x()\">b</li>\n</ul>"),
        );
    }

    public function testANestedListStillCountsAmongAllNodesOnTheWayIn(): void
    {
        // The item numbering is the list's own; inside an item the general rule
        // applies again, so the nested `<ul>` is the 2nd child node of the item
        // it sits in.
        $html = "<ul>\n<li>a\n<ul>\n<li onclick=\"x()\">n</li>\n</ul>\n</li>\n</ul>";

        $this->assertSame(['/ul[1]/li[1]/ul[2]/li[1]'], $this->paths($html));
    }
}
