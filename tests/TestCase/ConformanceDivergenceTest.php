<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use function trim;

/**
 * Focused regression tests for the spec-conformance divergence fixes pinned by
 * the canonical Carve corpus (markup-carve/carve). Each test mirrors a corpus
 * case's exact canonical output (oracle: carve-js); the F-id case is not
 * corpus-enforced but matches carve-js / carve-rs.
 *
 * Decision letters refer to the corpus conformance pins:
 *  - B newline in a link destination is literal
 *  - A reference-definition destination ends at the first whitespace
 *  - I `_` is a valid extension name
 *  - D inline-link title allows a backslash-escaped quote
 *  - SQ smart quote opens after an operator / opening punctuation
 *  - F-id a literal NBSP in a heading id serializes as the raw byte
 */
class ConformanceDivergenceTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * B: a newline counts as whitespace and ENDS the link destination, so a
     * `(` run reaching end-of-line without a closing `)` is not a link.
     */
    public function testNewlineInLinkDestinationIsLiteral(): void
    {
        $this->assertSame(
            "<p>[t](url\nmore)</p>",
            trim($this->converter->convert("[t](url\nmore)\n")),
        );
    }

    /**
     * A: the reference-definition destination ends at the first whitespace
     * (the rest is ignored), so `[r]: a b c` registers `a` as the href.
     */
    public function testReferenceDefinitionDestinationStopsAtFirstWhitespace(): void
    {
        $this->assertSame(
            '<p><a href="a">r</a></p>',
            trim($this->converter->convert("[r][r]\n\n[r]: a b c\n")),
        );
    }

    /**
     * A: a quoted title still attaches after the (whitespace-delimited)
     * destination.
     */
    public function testReferenceDefinitionQuotedTitleStillAttaches(): void
    {
        $this->assertSame(
            '<p><a href="/url" title="Title">r</a></p>',
            trim($this->converter->convert("[r][r]\n\n[r]: /url \"Title\"\n")),
        );
    }

    /**
     * A: a SINGLE-quoted title attaches too (it is not masked by the empty
     * double-quote alternation branch).
     */
    public function testReferenceDefinitionSingleQuotedTitleAttaches(): void
    {
        $this->assertSame(
            '<p><a href="/url" title="Title">r</a></p>',
            trim($this->converter->convert("[r][r]\n\n[r]: /url 'Title'\n")),
        );
    }

    /**
     * I: `_` is a valid identifier, so it is a valid inline-extension name.
     */
    public function testUnderscoreIsAValidExtensionName(): void
    {
        $this->assertSame(
            '<p><span class="ext-_">x</span></p>',
            trim($this->converter->convert(":_[x]\n")),
        );
    }

    /**
     * I: an empty `_`-named extension yields an empty span.
     */
    public function testUnderscoreExtensionWithEmptyContent(): void
    {
        $this->assertSame(
            '<p><span class="ext-_"></span></p>',
            trim($this->converter->convert(":_[]\n")),
        );
    }

    /**
     * D: an INLINE-link title may contain a backslash-escaped delimiter,
     * kept as a literal quote.
     */
    public function testInlineLinkTitleAllowsBackslashEscapedQuote(): void
    {
        $this->assertSame(
            '<p><a href="/url" title="ti&quot;tle">t</a></p>',
            trim($this->converter->convert("[t](/url \"ti\\\"tle\")\n")),
        );
    }

    /**
     * SQ: a straight quote opens after an operator / opening-punctuation
     * character (`= : - /` and `(`), and still closes after sentence
     * punctuation (`end."`).
     */
    public function testSmartQuoteOpensAfterOperatorOrOpeningPunctuation(): void
    {
        $this->assertSame(
            "<p>a=\u{201C}b\u{201D}\n:\u{201C}q\u{201D}\n-\u{201C}q\u{201D}\n"
            . "/\u{201C}q\u{201D}\n(\u{201C}q\u{201D})</p>",
            trim($this->converter->convert("a=\"b\"\n:\"q\"\n-\"q\"\n/\"q\"\n(\"q\")\n")),
        );
    }

    /**
     * SQ: a quote after sentence punctuation stays a CLOSING quote.
     */
    public function testSmartQuoteAfterSentencePunctuationCloses(): void
    {
        $this->assertSame(
            "<p>end.\u{201D}</p>",
            trim($this->converter->convert("end.\"\n")),
        );
    }

    /**
     * SQ: the same opener rule applies to single quotes.
     */
    public function testSmartSingleQuoteOpensAfterOperator(): void
    {
        $this->assertSame(
            "<p>:\u{2018}q\u{2019}</p>",
            trim($this->converter->convert(":'q'\n")),
        );
    }

    /**
     * SQ: a hard line break is whitespace, so a quote right after one opens
     * (consistent with a soft break).
     */
    public function testSmartQuoteOpensAfterHardBreak(): void
    {
        $this->assertSame(
            "<p>a<br>\n\u{201C}b\u{201D}</p>",
            trim($this->converter->convert("a\\\n\"b\"\n")),
        );
    }

    /**
     * F-id: a literal non-breaking space in a heading id serializes as the
     * raw U+00A0 byte (matching carve-js / carve-rs), while the heading text
     * keeps the `&nbsp;` entity.
     */
    public function testHeadingIdKeepsRawNonBreakingSpace(): void
    {
        $html = $this->converter->convert("# H\u{00A0}eading\n");

        $this->assertStringContainsString("id=\"H\u{00A0}eading\"", $html);
        $this->assertStringContainsString('H&nbsp;eading</h1>', $html);
        $this->assertStringNotContainsString('id="H&nbsp;eading"', $html);
    }
}
