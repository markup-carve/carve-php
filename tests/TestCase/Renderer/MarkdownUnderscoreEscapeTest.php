<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An underscore escape is kept only where it protects something.
 *
 * CommonMark does not honour an intraword underscore, so escaping one protects
 * nothing and only litters identifiers in output meant to be read and searched.
 * The same holds one step further out: emphasis needs an opener and a closer,
 * so an underscore with no possible partner in its block emphasises nothing
 * either, whatever its flanking says in isolation.
 *
 * An asterisk is not symmetric here - `a*b*c` does emphasise - so `*` stays
 * escaped everywhere.
 */
class MarkdownUnderscoreEscapeTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'single underscore' => ['company_id'],
            'several underscores' => ['a_b_c'],
            'snake case' => ['snake_case_name'],
            'longer identifier' => ['read_write_delete'],
        ];
    }

    #[DataProvider('identifierProvider')]
    public function testAnIntrawordUnderscoreIsLeftBare(string $identifier): void
    {
        $this->assertSame($identifier, trim(CarveConverter::markdown()->convert($identifier)));
    }

    /**
     * Emphasis needs an opener AND a closer after it, so a lone underscore
     * emphasises nothing whatever its flanking says in isolation. It used to
     * keep an escape anyway, which cost the same identifier legibility the
     * intraword rule above was introduced to recover.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unpairableProvider(): array
    {
        return [
            'trailing' => ['trailing_', 'trailing_'],
            'leading' => ['_leading', '_leading'],
            'after an opening paren' => ['(_tab-nav) and text', '(_tab-nav) and text'],
            'after a slash' => ['building_type/_l2 here', 'building_type/_l2 here'],
            'a charset introducer' => ['_utf8mb4 default', '_utf8mb4 default'],
            'two openers, no closer' => ['start _x and _y end', 'start _x and _y end'],
            'an authored escape with no partner' => ['\_only', '_only'],
        ];
    }

    #[DataProvider('unpairableProvider')]
    public function testAnUnderscoreWithNothingToPairWithLosesItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    /**
     * The moment a partner exists the escape is load-bearing again: dropping it
     * would let CommonMark read the pair as emphasis the author did not write.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pairableProvider(): array
    {
        return [
            'an authored pair' => ['\_a\_', '\_a\_'],
            'an authored pair in a sentence' => ['x \_a\_ y', 'x \_a\_ y'],
            'an authored strong pair' => ['\_\_a\_\_', '\_\_a\_\_'],
            'a pair spanning words' => ['a \_b c\_ d', 'a \_b c\_ d'],
        ];
    }

    #[DataProvider('pairableProvider')]
    public function testAnUnderscoreThatCouldStillPairKeepsItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    /**
     * Emphasis does not span a blank line, so the two paragraphs are judged
     * apart: neither underscore can reach the other and both come out bare.
     */
    public function testUnderscoresInSeparateBlocksDoNotPair(): void
    {
        $this->assertSame(
            "_first\n\n_second",
            trim(CarveConverter::markdown()->convert("\\_first\n\n\\_second")),
        );
    }

    public function testAnAsteriskBetweenWordCharactersStaysEscaped(): void
    {
        // `a*b*c` emphasises in CommonMark, unlike the underscore form.
        $this->assertSame('a\*b\*c', trim(CarveConverter::markdown()->convert('a*b*c')));
    }

    /**
     * The asterisk needs a partner as much as the underscore does; it just
     * finds one more often, because CommonMark lets it emphasise intraword.
     * Where no run in the block can close what another opened, the escape
     * protects nothing - `can_*`, `weights.*.weight`, a multiplication sign.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unpairableAsteriskProvider(): array
    {
        return [
            'a wildcard suffix' => ['can_* Familie', 'can_* Familie'],
            'a wildcard in a path' => ['aiFiles.* file', 'aiFiles.* file'],
            'a wildcard between words' => ['weights.*.weight required', 'weights.*.weight required'],
            'a multiplication sign' => ['fee_net * quantity', 'fee_net * quantity'],
            'two openers, no closer' => ['start *x and *y end', 'start *x and *y end'],
        ];
    }

    #[DataProvider('unpairableAsteriskProvider')]
    public function testAnAsteriskWithNothingToPairWithLosesItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    /**
     * Real emphasis is untouched: Carve's own `*strong*` still renders, and an
     * asterisk pair the author escaped keeps both escapes.
     */
    public function testAsteriskEmphasisAndAuthoredPairsAreUntouched(): void
    {
        $this->assertSame('**bold**', trim(CarveConverter::markdown()->convert('*bold*')));
        $this->assertSame('x **y** z', trim(CarveConverter::markdown()->convert('x *y* z')));
        $this->assertSame('a \*b\* c', trim(CarveConverter::markdown()->convert('a \*b\* c')));
    }

    /**
     * A hash is special at the start of a line, where it opens an ATX heading,
     * and after a `{`, where it opens the attribute block this renderer emits
     * on a heading a crossref points at. Anywhere else it is an ordinary
     * character and the backslash only breaks search on the identifier.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inertHashProvider(): array
    {
        return [
            'a language name' => ['C# and F#', 'C# and F#'],
            'an issue reference' => ['issue #123 here', 'issue #123 here'],
            'a bare hash mid-line' => ['text # mid', 'text # mid'],
            'a tag' => ['a #tag b', 'a #tag b'],
            'no space after the hash' => ['#hashtag', '#hashtag'],
        ];
    }

    #[DataProvider('inertHashProvider')]
    public function testAHashThatCannotOpenAnythingLosesItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    /**
     * The attribute-block spelling stays escaped so a literal `{#...}` in text
     * cannot be read as the `{#id}` anchor this renderer writes for real.
     */
    public function testAHashOpeningAnAttributeBlockKeepsItsEscape(): void
    {
        $this->assertSame('{\#foo)} literal', trim(CarveConverter::markdown()->convert('{#foo)} literal')));
    }

    public function testCodeSpansAreUntouched(): void
    {
        $this->assertSame('`code_span`', trim(CarveConverter::markdown()->convert('`code_span`')));
    }

    /**
     * A backslash the author typed is content, not an escape this renderer
     * added. The de-escaping used to run over the assembled document, where it
     * could not tell the two apart, and rewrote verbatim regions that carry a
     * literal backslash before an underscore (carve-js issue 400).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verbatimProvider(): array
    {
        return [
            'code span' => ['`a\_b`', '`a\_b`'],
            'code block' => ["```\ncompany\\_id\n```", "```\ncompany\\_id\n```"],
            'link destination' => ['[x](a\_b)', '[x](a\_b)'],
            'image source' => ['![a](x\_y)', '![a](x\_y)'],
            'raw html' => ["```=html\n<i>a\\_b</i>\n```", '&lt;i&gt;a\_b&lt;/i&gt;'],
        ];
    }

    #[DataProvider('verbatimProvider')]
    public function testABackslashTheRendererDidNotWriteIsKept(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    public function testAnAuthoredEscapeIsDeEscapedWhenIntraword(): void
    {
        // `a\_b` and `a_b` are two spellings of the same document, so they have
        // to render the same - the escape the author wrote is still an escape.
        $this->assertSame('a_b', trim(CarveConverter::markdown()->convert('a\_b')));
    }

    public function testUnderlineEmphasisStillRenders(): void
    {
        $this->assertSame('<u>underline</u>', trim(CarveConverter::markdown()->convert('_underline_')));
    }

    public function testAnIdentifierBesideRealEmphasis(): void
    {
        $this->assertSame(
            'company_id and **strong**',
            trim(CarveConverter::markdown()->convert('company_id and *strong*')),
        );
    }
}
