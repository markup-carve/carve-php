<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

/**
 * `CARVE-P10-010` (markup-carve/carve#2296) splits a placed element in two. Its
 * opening and closing tags may carry the ambient indentation or sit at column 0,
 * and one column serves both. The GENERATED CONTENT between them is the
 * cross-implementation contract, byte-identical including its own leading
 * whitespace, and where it is anchored is the extension's own statement: `::: toc`
 * writes the `<ul>`, its items and the closing `</ul>` at column 0 wherever the
 * marker was written, while a `glossary` row and an `index` item sit one level
 * inside their own element and move with the column it took.
 *
 * The `toc` half of carve-php#2396 is fixed in carve-php#2418; what it left open
 * is the rest of the clause. THE OTHER TWO KINDS ARE MEASURED HERE, so a later
 * change cannot flatten all three alike, and
 * `testFlatteningTheDocumentHidesTheDefect` shows why every earlier toc test was
 * blind: at top level the same assertions pass with the fix and without it.
 */
class GeneratedContentKeepsItsOwnColumnTest extends TestCase
{
    private function html(string $source, ExtensionInterface $extension): string
    {
        $converter = new CarveConverter();
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    /**
     * The column of each line that holds the given tag, in document order.
     *
     * @return list<int>
     */
    private static function columnsOf(string $html, string $tag): array
    {
        $columns = [];
        foreach (explode("\n", $html) as $line) {
            if (str_contains($line, $tag)) {
                $columns[] = strlen($line) - strlen(ltrim($line, ' '));
            }
        }

        return $columns;
    }

    /**
     * @var string
     */
    private const NESTED_TOC = "# Heading\n\n::: toc\n:::\n";

    /**
     * @var string
     */
    private const FLAT_TOC = "::: toc\n:::\n\n# Heading\n";

    /**
     * @var string
     */
    private const GLOSSARY_BODY = "::: glossary\n:: HTTP\n:  HyperText Transfer Protocol.\n:::\n";

    /**
     * @var string
     */
    private const INDEX_BODY = "A :index[widget] here.\n\n::: index\n:::\n";

    public function testTheTocListSitsAtColumnZeroUnderAnIndentedMarker(): void
    {
        $html = $this->html(self::NESTED_TOC, new TocPlacementExtension());

        $this->assertSame([2], self::columnsOf($html, '<nav'));
        $this->assertSame([0], self::columnsOf($html, '<ul>'));
        $this->assertSame([0], self::columnsOf($html, '<li>'));
        $this->assertSame([0], self::columnsOf($html, '</ul>'));
    }

    /**
     * One column serves both of the element's own tags. An opener at the ambient
     * column with a closer at 0 conforms under neither choice the clause offers.
     */
    public function testTheNavSTwoTagsShareOneColumn(): void
    {
        $html = $this->html(self::NESTED_TOC, new TocPlacementExtension());

        $this->assertSame(
            self::columnsOf($html, '<nav'),
            self::columnsOf($html, '</nav>'),
        );
    }

    /**
     * The contract itself: the generated lines are the same bytes wherever the
     * marker sits. Before the fix the nested document's list carried two extra
     * columns on every line.
     */
    public function testTheGeneratedListIsByteIdenticalAtEveryMarkerColumn(): void
    {
        $nested = $this->html(self::NESTED_TOC, new TocPlacementExtension());
        $flat = $this->html(self::FLAT_TOC, new TocPlacementExtension());

        $this->assertSame(
            self::generatedLines($flat),
            self::generatedLines($nested),
        );
        $this->assertSame(
            ['<ul>', '<li><a href="#Heading">Heading</a></li>', '</ul>'],
            self::generatedLines($nested),
        );
    }

    /**
     * The nav's own children - a quoted title and a label - are generated content
     * too, so they sit at column 0 with the list.
     */
    public function testTheNavSTitleAndLabelSitAtColumnZeroToo(): void
    {
        $html = $this->html("# Heading\n\n::: toc \"On this page\" [End]\n:::\n", new TocPlacementExtension());

        $this->assertSame([0], self::columnsOf($html, 'class="admonition-title"'));
        $this->assertSame([0], self::columnsOf($html, 'class="div-label"'));
        $this->assertSame([0], self::columnsOf($html, '<ul>'));
    }

    /**
     * Two levels of ambient indentation, so the reading is not "the fix subtracts
     * two spaces".
     */
    public function testTheListStaysAtColumnZeroTwoContainersDeep(): void
    {
        $html = $this->html("# Heading\n\n> ::: toc\n> :::\n", new TocPlacementExtension());

        $this->assertSame([4], self::columnsOf($html, '<nav'));
        $this->assertSame([4], self::columnsOf($html, '</nav>'));
        $this->assertSame([0], self::columnsOf($html, '<ul>'));
        $this->assertSame([0], self::columnsOf($html, '</ul>'));
    }

    /**
     * The other two kinds the one wording governs. A `glossary` row and an
     * `index` item are anchored one level inside their own element, so they MOVE
     * with the column that element took - the opposite of the `toc` list, and the
     * reason this is not "flatten all three alike".
     */
    public function testAGlossaryRowStaysOneLevelInsideItsOwnElement(): void
    {
        $flat = $this->html(self::GLOSSARY_BODY, new GlossaryExtension());
        $nested = $this->html("# Heading\n\n" . self::GLOSSARY_BODY, new GlossaryExtension());

        $this->assertSame([0], self::columnsOf($flat, '<dl'));
        $this->assertSame([2], self::columnsOf($flat, '<dt'));
        $this->assertSame([2], self::columnsOf($nested, '<dl'));
        $this->assertSame([4], self::columnsOf($nested, '<dt'));
    }

    public function testAnIndexItemStaysOneLevelInsideItsOwnElement(): void
    {
        $flat = $this->html(self::INDEX_BODY, new IndexExtension());
        $nested = $this->html("# Heading\n\n" . self::INDEX_BODY, new IndexExtension());

        $this->assertSame([0], self::columnsOf($flat, '<ul class="index">'));
        $this->assertSame([2], self::columnsOf($flat, '<li>'));
        $this->assertSame([2], self::columnsOf($nested, '<ul class="index">'));
        $this->assertSame([4], self::columnsOf($nested, '<li>'));
    }

    /**
     * PROOF THE GUARD FIRES. Flatten the document to top level - the position
     * every other test in the suite uses - and the `toc` assertions hold with the
     * fix and without it, because at column 0 there is no ambient indent to
     * propagate. A fixture at the easy position cannot see this class, which is
     * why it went unnoticed.
     */
    public function testFlatteningTheDocumentHidesTheDefect(): void
    {
        $flat = $this->html(self::FLAT_TOC, new TocPlacementExtension());

        $this->assertSame([0], self::columnsOf($flat, '<nav'));
        $this->assertSame([0], self::columnsOf($flat, '<ul>'));
        $this->assertSame([0], self::columnsOf($flat, '</ul>'));
        $this->assertNotSame(
            self::columnsOf($flat, '<nav'),
            self::columnsOf($this->html(self::NESTED_TOC, new TocPlacementExtension()), '<nav'),
        );
    }

    /**
     * The nav's lines between its own two tags.
     *
     * @return list<string>
     */
    private static function generatedLines(string $html): array
    {
        $lines = explode("\n", $html);
        $open = null;
        $close = null;
        foreach ($lines as $i => $line) {
            if ($open === null && str_contains($line, '<nav')) {
                $open = $i;
            } elseif ($open !== null && str_contains($line, '</nav>')) {
                $close = $i;

                break;
            }
        }
        self::assertNotNull($open, 'no <nav> in the output');
        self::assertNotNull($close, 'no </nav> in the output');

        return array_values(array_slice($lines, $open + 1, $close - $open - 1));
    }
}
