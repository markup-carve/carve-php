<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A LINE FOLLOWING A CONSUMED DEFINITION inside a nested note is placed by
 * COLUMN-REACH, not by which note the definition was written in.
 *
 * A consumed definition stores no continuation claim (PART 9 layout
 * `01-layout.ebnf` 132-146), so the trailing line is placed purely by its own
 * indentation column p, by the existing owner-selection rule [CARVE-P0-004]:
 * it belongs to the innermost open note whose BODY CONTENT COLUMN (marker + 2)
 * p reaches. With outer marker 0, mid marker `m` and inner marker `i` the
 * bodies sit at Bo = 2, Bm = m + 2 and Bi = i + 2:
 *
 * - p >= Bi is the inner note.
 * - Bm <= p < Bi is the mid note.
 * - Bo <= p < Bm is the outer note.
 * - p < Bo is document text.
 *
 * The defect this pins (markup-carve/carve-php#1895): the reach machinery had
 * only two sinks - leave the line in the inner note, or lift it flush to the
 * outer one - so a line in the band [Bm, Bi) landed in the OUTER note instead
 * of the MID note it reaches. Every canonical check below is byte-identical to
 * carve-js `ac5e6902` rendered through the same inputs.
 */
class ATrailingLineAfterAConsumedDefinitionIsPlacedByColumnReachTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * A three-level note stack whose innermost note's body holds a reference
     * definition and a trailing word, both written at column p.
     *
     * @param int $mid The mid note's marker column.
     * @param int $inner The inner note's marker column.
     * @param int $payload The column of the consumed definition and the line below it.
     */
    protected function document(int $mid, int $inner, int $payload): string
    {
        $sp = static fn (int $n): string => str_repeat(' ', $n);

        return "[^f]: outer\n"
            . "\n"
            . $sp($mid) . "[^g]: mid\n"
            . "\n"
            . $sp($inner) . "[^h]: inner\n"
            . "\n"
            . $sp($payload) . "[r]: /url\n"
            . $sp($payload) . "TAILWORD\n"
            . "\n"
            . "x[^f] [^g] [^h] [t][r]\n";
    }

    /**
     * p = 7 with inner body column 6 reaches the inner note. This already held
     * before the fix and must stay.
     */
    public function testPastTheInnerBodyColumnLandsInTheInnerNote(): void
    {
        $html = trim($this->converter->convert($this->document(mid: 2, inner: 4, payload: 7)));

        $expected = "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> <a id=\"fnref3\" href=\"#fn3\" role=\"doc-noteref\"><sup>3</sup></a> <a href=\"/url\">t</a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>outer<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn2\">\n"
            . "      <p>mid<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn3\">\n"
            . "      <p>inner</p>\n"
            . "      <p>TAILWORD<a href=\"#fnref3\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . '</section>';

        $this->assertSame($expected, $html);
    }

    /**
     * p = 4 with mid body column 4 and inner body column 8 reaches the mid note
     * and stops short of the inner one. This is the band the two-sink form
     * dropped into the OUTER note.
     */
    public function testInTheMidBandLandsInTheMidNote(): void
    {
        $html = trim($this->converter->convert($this->document(mid: 2, inner: 6, payload: 4)));

        $expected = "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> <a id=\"fnref3\" href=\"#fn3\" role=\"doc-noteref\"><sup>3</sup></a> <a href=\"/url\">t</a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>outer<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn2\">\n"
            . "      <p>mid</p>\n"
            . "      <p>TAILWORD<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn3\">\n"
            . "      <p>inner<a href=\"#fnref3\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . '</section>';

        $this->assertSame($expected, $html);
    }

    /**
     * p = 2 with mid body column 4 reaches only the outer note's body column 2.
     * This is corpus 456's band and must stay outer.
     */
    public function testInTheOuterBandLandsInTheOuterNote(): void
    {
        $html = trim($this->converter->convert($this->document(mid: 2, inner: 4, payload: 2)));

        $expected = "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> <a id=\"fnref3\" href=\"#fn3\" role=\"doc-noteref\"><sup>3</sup></a> <a href=\"/url\">t</a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>outer</p>\n"
            . "      <p>TAILWORD<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn2\">\n"
            . "      <p>mid<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "    <li id=\"fn3\">\n"
            . "      <p>inner<a href=\"#fnref3\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . '</section>';

        $this->assertSame($expected, $html);
    }

    /**
     * p = 1 is below the outer note's body column 2, so the definition and the
     * line below it leave every note and become document text; `[t][r]` never
     * resolves. Without this row the band is satisfied by a rule that keeps
     * pulling lines into the outer note.
     */
    public function testBelowTheOuterBodyColumnIsDocumentText(): void
    {
        $html = $this->converter->convert($this->document(mid: 2, inner: 4, payload: 1));

        $this->assertStringContainsString("<p>[r]: /url\nTAILWORD</p>", $html);
        $this->assertStringContainsString('[t][r]', $html);
        $this->assertStringNotContainsString('href="/url"', $html);
    }
}
