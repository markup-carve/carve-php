<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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
 *
 * Round-3 conformance fixes (corpus 24713c7) and the php-specific edge
 * alignments to the carve-js oracle:
 *  - autolink url_char body excludes `" \ ` { } | ^`
 *  - reference-definition title unescapes an escaped quote
 *  - an empty reference definition is literal (destination required)
 *  - inline-extension `ext-NAME` class comes first
 *  - inline-link title unescapes any `\` + ASCII-punctuation
 *  - email-autolink char edges (`[`/`]` literal, leading `.` valid)
 *  - reference definitions are single-line (no continuation gathering)
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
     * SQ: a straight quote right after a line break (soft OR hard) is
     * word-adjacent, so it stays CLOSING. This matches the corpus-pinned
     * smart-typography case (`a"b\n""` -> `a”b\n””`) and the carve-js oracle,
     * which keys quote context off the output buffer and sees a flushed buffer
     * with prior output as word context. (carve-rs diverges here and opens the
     * quote after a break; the corpus follows carve-js.)
     */
    public function testSmartQuoteClosesAfterHardBreak(): void
    {
        $this->assertSame(
            "<p>a<br>\n\u{201D}b\u{201D}</p>",
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

    /**
     * Item 1 (corpus 59-autolinks-3): a url_char in the autolink body excludes
     * `"` `\` `` ` `` `{` `}` `|` `^`; any of them invalidates the construct, so
     * the whole `<...>` stays literal. A clean URL still autolinks.
     */
    public function testAutolinkBodyExcludesNonUrlChars(): void
    {
        $this->assertSame(
            '<p>&lt;http://a.com/“q”&gt;</p>',
            trim($this->converter->convert("<http://a.com/\"q\">\n")),
        );
        $this->assertSame(
            '<p>&lt;http://a.com/a|b&gt;</p>',
            trim($this->converter->convert("<http://a.com/a|b>\n")),
        );
        $this->assertSame(
            '<p><a href="http://a.com/p?x=1">http://a.com/p?x=1</a></p>',
            trim($this->converter->convert("<http://a.com/p?x=1>\n")),
        );
    }

    /**
     * Item 2 (corpus 34-reference-link-7): a reference-definition title
     * unescapes a backslash-escaped quote, the same as inline link titles.
     */
    public function testReferenceDefinitionTitleUnescapesEscapedQuote(): void
    {
        $this->assertSame(
            '<p><a href="/u" title="a&quot;b&quot;c">x</a></p>',
            trim($this->converter->convert("[x][y]\n\n[y]: /u \"a\\\"b\\\"c\"\n")),
        );
    }

    /**
     * Item 3 (corpus 34-reference-link-8/9): an empty reference definition is
     * literal -- the destination must be present on the def line.
     */
    public function testEmptyReferenceDefinitionIsLiteral(): void
    {
        $this->assertSame(
            '<p>[r]:</p>',
            trim($this->converter->convert("[r]:\n")),
        );
        $this->assertSame(
            '<p>[r]:</p>',
            trim($this->converter->convert("[r]:   \n")),
        );
    }

    /**
     * Item 3 (regression): the destination-required rule must still accept a
     * tab in the separator run, so a `[r]: \t/u` definition resolves (only a
     * truly empty destination is rejected).
     */
    public function testReferenceDefinitionAllowsTabSeparator(): void
    {
        $this->assertSame(
            '<p>see <a href="/u">x</a></p>',
            trim($this->converter->convert("see [x][r]\n\n[r]: \t/u\n")),
        );
    }

    /**
     * Item 4 (corpus 16-inline-extensions-6): the structural `ext-NAME` class
     * comes FIRST, before authored classes.
     */
    public function testInlineExtensionClassOrderIsStructuralFirst(): void
    {
        $this->assertSame(
            '<p><span class="ext-foo cls">a</span></p>',
            trim($this->converter->convert(":foo[a]{.cls}\n")),
        );
    }

    /**
     * Item 5 (php-specific, oracle carve-js): an inline link title unescapes
     * ANY backslash + ASCII-punctuation, not just `\"` / `\'`.
     */
    public function testInlineLinkTitleUnescapesAllPunctuation(): void
    {
        $this->assertSame(
            '<p><a href="/u" title="a.b">t</a></p>',
            trim($this->converter->convert("[t](/u \"a\\.b\")\n")),
        );
    }

    /**
     * Item 6 (php-specific, oracle carve-js): email-autolink edges. `[`/`]` are
     * not email chars (literal); a leading `.` is a valid email char (mailto).
     */
    public function testEmailAutolinkCharEdges(): void
    {
        $this->assertSame(
            '<p>&lt;a@[127.0.0.1]&gt;</p>',
            trim($this->converter->convert("<a@[127.0.0.1]>\n")),
        );
        $this->assertSame(
            '<p><a href="mailto:.a@b.com">.a@b.com</a></p>',
            trim($this->converter->convert("<.a@b.com>\n")),
        );
    }

    /**
     * Item 7 (php-specific, oracle carve-js): reference definitions are
     * single-line. A title on a continuation line is NOT gathered into the
     * def -- the def keeps just the same-line destination and the trailing
     * lines render as their own paragraph.
     */
    public function testReferenceDefinitionDoesNotGatherContinuationTitle(): void
    {
        $this->assertSame(
            "<p>“multi\nline”</p>\n<p><a href=\"/u\">ref</a></p>",
            trim($this->converter->convert("[x]: /u\n  \"multi\nline\"\n\n[ref][x]\n")),
        );
    }
}
