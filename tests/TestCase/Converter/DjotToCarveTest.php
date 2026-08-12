<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotToCarveTest extends TestCase
{
    protected DjotToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotToCarve();
    }

    public function testEmphasisUnderscoreBecomesSlash(): void
    {
        $this->assertSame('/text/', $this->converter->convert('_text_'));
    }

    public function testSubscriptTildeBecomesCommas(): void
    {
        $this->assertSame('H{,2,}O', $this->converter->convert('H~2~O'));
    }

    public function testMathSpanWithCaretIsUntouched(): void
    {
        $input = 'inline $`x^2 + y^3` math';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testMathSpanWithTildeIsUntouched(): void
    {
        $input = 'display $$`a~b + c~d` math';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testBareDollarTextStillConverts(): void
    {
        // Bare $...$ is NOT math in Djot or Carve; delimiters inside it are
        // real superscript/subscript markup and must still be migrated.
        $this->assertSame(
            'Costs $2{^x^}$ now',
            $this->converter->convert('Costs $2^x^$ now'),
        );
    }

    public function testFootnoteReferenceIsUntouched(): void
    {
        $input = 'See this[^f1].';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testTwoFootnoteReferencesAreUntouched(): void
    {
        $input = 'See this[^a] and that[^b].';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testFootnoteDefinitionLineIsUntouched(): void
    {
        $input = "[^a]: first\n[^b]: second";
        $this->assertSame($input, $this->converter->convert($input));
    }

    /**
     * The braced superscript is spelled the same in both languages and means
     * superscript in both, so the conversion is the identity - which is what
     * the name has always said. It previously escaped the brace instead,
     * turning the superscript into literal text.
     *
     * carve-js's `djot-migrate`, which this converter's docblock names as its
     * canonical source, excludes this form from its superscript rule in so many
     * words: "the braced `{^x^}` form, which is valid in both languages".
     */
    public function testPreBracedForcedSuperscriptIsUntouched(): void
    {
        $this->assertSame('{^x^}', $this->converter->convert('{^x^}'));
    }

    /**
     * Djot spells subscript bare and braced and means the same by each, so the
     * braced form converts exactly like the bare one. It previously escaped
     * instead, dropping the subscript.
     */
    public function testBracedSubscriptConvertsLikeTheBareForm(): void
    {
        $this->assertSame('{,y,}', $this->converter->convert('{~y~}'));
        $this->assertSame(
            $this->converter->convert('~y~'),
            $this->converter->convert('{~y~}'),
        );
    }

    /**
     * BOUND: the bare superscript still needs the braced form, and a Carve
     * construct that Djot does not share is still escaped. Neither row moves
     * under this change - they are here so a fix cannot pass by exempting every
     * braced delimiter from escaping.
     */
    public function testTheSurroundingRulesAreUnchanged(): void
    {
        $this->assertSame('{^x^}', $this->converter->convert('^x^'));
        $this->assertSame('\\{,y,}', $this->converter->convert('{,y,}'));
    }

    public function testPreBracedForcedSubscriptIsUntouched(): void
    {
        $this->assertSame('\\{,x,}', $this->converter->convert('{,x,}'));
    }

    public function testMixedProtectedConstructsAndRealSuperscript(): void
    {
        $input = 'Math $`x^2 + y^3`, note[^a], real ^x^.';
        $this->assertSame('Math $`x^2 + y^3`, note[^a], real {^x^}.', $this->converter->convert($input));
    }

    public function testHighlightBracesBecomesEquals(): void
    {
        $this->assertSame('{=important=}', $this->converter->convert('{=important=}'));
    }

    public function testMarkdownStrongBecomesSingleStar(): void
    {
        $this->assertSame('*bold*', $this->converter->convert('**bold**'));
    }

    public function testMarkdownStrikethroughBecomesSingleTilde(): void
    {
        $this->assertSame('~struck~', $this->converter->convert('~~struck~~'));
    }

    public function testNestedDifferentFamilies(): void
    {
        $this->assertSame('~/x/~', $this->converter->convert('~~_x_~~'));
    }

    public function testEmphasisWithSubscriptNests(): void
    {
        $this->assertSame('/{,x,}/', $this->converter->convert('_~x~_'));
    }

    public function testCodeSpanIsUntouched(): void
    {
        $this->assertSame('`_x_`', $this->converter->convert('`_x_`'));
    }

    public function testFencedBlockIsUntouched(): void
    {
        $input = "```\n_x_\n```";
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testLinkDestinationIsUntouched(): void
    {
        $this->assertSame('[home](/~user/index)', $this->converter->convert('[home](/~user/index)'));
    }

    public function testEscapedDelimiterLeftLiteral(): void
    {
        $this->assertSame('\\_x_', $this->converter->convert('\\_x_'));
    }

    /**
     * Pins the converter's one deliberate divergence from Djot.
     *
     * Djot's spec puts NO word boundary on emphasis - a `_` opens when not
     * followed by whitespace and closes when not preceded by whitespace - so a
     * strict reader emphasizes this pair, and pandoc's Djot reader renders
     * `snake<em>case</em>word`. The converter leaves it literal instead,
     * because the documents it exists for are full of identifiers no author
     * meant as emphasis.
     *
     * So this is not "the pattern happens not to match". It is a choice about
     * intent whose cost is that a document which DID mean emphasis inside a
     * word loses it silently.
     */
    public function testWordInternalUnderscoreLeftLiteralByIntent(): void
    {
        $this->assertSame('snake_case_word', $this->converter->convert('snake_case_word'));
    }

    /**
     * A TAG is the one Carve inline construct that is not a pair: `#x` opens on
     * its own, so nothing downstream neutralizes it and escaping an enclosing
     * brace cannot either.
     *
     * Djot has no hashtag at all - pandoc's Djot reader renders `a #y b` as
     * `<p>a #y b</p>` - so every `#word` in Djot prose became a Carve tag span
     * that existed nowhere in the source (carve-php#1191).
     */
    public function testHashDoesNotBecomeATag(): void
    {
        $this->assertSame('a \\#y b', $this->converter->convert('a #y b'));
        $this->assertSame('a \\#1 b', $this->converter->convert('a #1 b'));
    }

    /**
     * The braced case from the report, which is the rarest instance rather than
     * the whole defect: escaping the brace alone left the inner `#` opening a
     * tag inside literal braces.
     */
    public function testBracedHashIsFullyLiteral(): void
    {
        $this->assertSame('\\{\\#y#} x', $this->converter->convert('{#y#} x'));
    }

    /**
     * BOUND: a NUMERIC CHARACTER REFERENCE carries a `#` that is not a tag.
     * Escaping it stopped `&#8212;` decoding, so the em dash never appeared -
     * caught by carve-js's entity tests, which this engine had no counterpart
     * for.
     */
    public function testNumericCharacterReferenceKeepsItsHash(): void
    {
        $this->assertSame('a &#8212; b', $this->converter->convert('a &#8212; b'));
        $this->assertSame('a &#x2014; b', $this->converter->convert('a &#x2014; b'));
    }

    /**
     * BOUND: a heading is `#` followed by a SPACE and is shared with Djot, and
     * `a#y` is not a tag either. Neither may gain a backslash.
     */
    public function testHeadingAndIntrawordHashAreUntouched(): void
    {
        $this->assertSame('# Heading', $this->converter->convert('# Heading'));
        $this->assertSame('a#y b', $this->converter->convert('a#y b'));
    }

    public function testUnchangedConstructs(): void
    {
        // Critic markup is identical in Djot and Carve, so it passes through.
        $input = 'plain text {+ins+} {-del-}';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testPlusBulletBecomesDash(): void
    {
        // Djot allows `+` bullets; Carve does not (it is the continuation
        // marker), so a `+` list is normalized to `-` to survive conversion.
        $this->assertSame("- one\n- two", $this->converter->convert("+ one\n+ two"));
    }

    public function testIndentedPlusBulletBecomesDash(): void
    {
        $this->assertSame("- a\n  - b", $this->converter->convert("+ a\n  + b"));
    }

    public function testLonePlusContinuationMarkerIsUntouched(): void
    {
        // A lone `+` is the Carve list-continuation marker, not a bullet.
        $this->assertSame("- item\n+\n> note", $this->converter->convert("- item\n+\n> note"));
    }

    public function testPlusBulletInFencedBlockIsUntouched(): void
    {
        $input = "```\n+ literal\n```";
        $this->assertSame($input, $this->converter->convert($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function literalCarveInlineTextProvider(): array
    {
        return [
            'bare slash' => ['a /it/ b', 'a /it/ b'],
            'bare equals' => ['a =hi= b', 'a =hi= b'],
            'braced subscript' => ['a {,y,} b', 'a {,y,} b'],
            // `{^y^}` and `{~y~}` are NOT plain Djot text - Djot renders them
            // as superscript and subscript - so they belong to the conversion
            // tests above, not here. They were listed as literals, which is
            // what made the converter escape them.
            'braced emphasis' => ['a {/y/} b', 'a {/y/} b'],
            'braced comment' => ['a {#y#} b', 'a {#y#} b'],
            'percent comments' => ['a %%c%% b', 'a %%c%% b'],
        ];
    }

    #[DataProvider('literalCarveInlineTextProvider')]
    public function testPlainDjotTextDoesNotBecomeCarveMarkup(string $input, string $literal): void
    {
        $html = (new CarveConverter())->convert($this->converter->convert($input));

        $this->assertStringContainsString($literal, strip_tags($html));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function djotNegativeEscapeProvider(): array
    {
        return [
            'path' => ['a/b/c'],
            'fraction' => ['1/2'],
            'assignment chain' => ['x = y = z'],
            'approximate number' => ['~5'],
            'percent' => ['50%'],
            'ftp url' => ['ftp://x/'],
            'protocol-relative url' => ['//host/path'],
            'file url' => ['file:///etc/hosts'],
        ];
    }

    #[DataProvider('djotNegativeEscapeProvider')]
    public function testDjotEscapePassDoesNotOverEscape(string $input): void
    {
        $html = (new CarveConverter())->convert($this->converter->convert($input));

        $this->assertStringContainsString($input, strip_tags($html));
    }

    public function testDjotSourceConstructBaselinesStillConvert(): void
    {
        $cases = [
            '_em_' => '<em>em</em>',
            '*strong*' => '<strong>strong</strong>',
            '~sub~' => '<sub>sub</sub>',
            '^sup^' => '<sup>sup</sup>',
            '{=hl=}' => '<mark>hl</mark>',
            '{+ins+}' => '<ins>ins</ins>',
            '{-del-}' => '<del>del</del>',
            '`code`' => '<code>code</code>',
            '[t](/u)' => '<a href="/u">t</a>',
        ];

        $renderer = new CarveConverter();
        foreach ($cases as $input => $expectedHtml) {
            $html = $renderer->convert($this->converter->convert($input));
            $this->assertStringContainsString($expectedHtml, $html, $input);
        }
    }

    /**
     * Performance guard: the same-family overlap check is O(n log n), not the
     * old O(n^2) linear scan over every prior match. A large emphasis-heavy
     * input must complete quickly and produce the correct output. The bound is
     * generous (the quadratic version took ~14s for this input); it only fails
     * on a regression back to super-linear behavior.
     */
    public function testLargeInputCompletesQuicklyWithCorrectOutput(): void
    {
        $input = str_repeat("_text_\n", 10000);

        $start = microtime(true);
        $result = $this->converter->convert($input);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'DjotToCarve large input should stay sub-quadratic');
        $this->assertSame(10000, substr_count($result, '/text/'));
        $this->assertStringStartsWith('/text/', $result);
    }
}
