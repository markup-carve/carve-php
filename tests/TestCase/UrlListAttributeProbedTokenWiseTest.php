<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §25: a URL-list attribute is probed at every candidate, not at its
 * head (markup-carve/carve#1320).
 *
 * The leading-scheme probe vouches for a whole value only where the whole
 * value is one URL. Four attributes carry a LIST of URLs a consumer resolves
 * or fetches, so before this rule the SAME value got two answers depending on
 * where the scheme sat: blanked in position one, emitted verbatim in position
 * two. For those four names the value is split into tokens, every non-empty
 * token gets the same probe, and any hit blanks the entire value.
 *
 * BOTH DIRECTIONS ARE ASSERTED HERE, and the second is the load-bearing half.
 * A dangerous scheme in a non-leading token must be refused, AND a legitimate
 * prose value carrying a colon must NOT be, because `title`, `alt` and
 * `aria-label` carry colons routinely and a blanket "any token that looks like
 * a scheme" test would refuse ordinary text. Without the negative direction
 * the next person to see this fire spuriously loosens it.
 */
class UrlListAttributeProbedTokenWiseTest extends TestCase
{
    /**
     * @var string A narrow no-break space (U+202F): Unicode whitespace, but
     *   NOT ASCII whitespace, which is the distinction the rule turns on.
     */
    protected const NNBSP = "\u{202F}";

    /**
     * @var string A zero-width space (U+200B): category `Cf`, which the clause
     *   leaves alone at every position.
     */
    protected const ZWSP = "\u{200B}";

    protected function render(string $carve): string
    {
        // No safe mode configured: this hardening is always on.
        return trim((new CarveConverter())->convert($carve));
    }

    /**
     * Every one of the four names, probed at a NON-LEADING token.
     *
     * One row per attribute rather than one row for `srcset` and a shrug at
     * the rest: with four names and two separator rules, the plausible defect
     * is one attribute the tests never reach.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonLeadingCandidateProvider(): array
    {
        return [
            'srcset' => [
                '![a](safe.png){srcset="safe.png 1x, javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" srcset="">',
            ],
            'imagesrcset' => [
                '![a](safe.png){imagesrcset="safe.png 1x, javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" imagesrcset="">',
            ],
            'ping' => [
                '[y](safe.html){ping="safe.html javascript:alert(1)"}',
                '<p><a href="safe.html" ping="">y</a></p>',
            ],
            'attributionsrc' => [
                '[y](safe.html){attributionsrc="https://example.com/s javascript:alert(1)"}',
                '<p><a href="safe.html" attributionsrc="">y</a></p>',
            ],
        ];
    }

    #[DataProvider('nonLeadingCandidateProvider')]
    public function testDangerousSchemeInANonLeadingCandidateBlanksTheWholeValue(
        string $carve,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * The position-one spelling, which already worked, still does.
     *
     * The defect was that these two disagreed; a fix that moved the
     * disagreement rather than removing it would pass the provider above.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function leadingCandidateProvider(): array
    {
        return [
            'srcset' => [
                '![a](safe.png){srcset="javascript:alert(1) 1x, safe.png 2x"}',
                '<img src="safe.png" alt="a" srcset="">',
            ],
            'imagesrcset' => [
                '![a](safe.png){imagesrcset="javascript:alert(1) 1x, safe.png 2x"}',
                '<img src="safe.png" alt="a" imagesrcset="">',
            ],
            'ping' => [
                '[y](safe.html){ping="javascript:alert(1) safe.html"}',
                '<p><a href="safe.html" ping="">y</a></p>',
            ],
            'attributionsrc' => [
                '[y](safe.html){attributionsrc="javascript:alert(1) https://example.com/s"}',
                '<p><a href="safe.html" attributionsrc="">y</a></p>',
            ],
        ];
    }

    #[DataProvider('leadingCandidateProvider')]
    public function testDangerousSchemeInTheLeadingCandidateStillBlanks(
        string $carve,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * A clean list is left exactly as authored, for all four names.
     *
     * The blanket mutant - blank every URL-list value - passes both providers
     * above and is only killed here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cleanListProvider(): array
    {
        return [
            'srcset' => [
                '![a](safe.png){srcset="a.png 1x, b.png 2x"}',
                '<img src="safe.png" alt="a" srcset="a.png 1x, b.png 2x">',
            ],
            'imagesrcset' => [
                '![a](safe.png){imagesrcset="a.png 1x, b.png 2x"}',
                '<img src="safe.png" alt="a" imagesrcset="a.png 1x, b.png 2x">',
            ],
            'ping' => [
                '[y](safe.html){ping="https://example.com/a https://example.com/b"}',
                '<p><a href="safe.html" ping="https://example.com/a https://example.com/b">y</a></p>',
            ],
            'attributionsrc' => [
                '[y](safe.html){attributionsrc="https://example.com/a https://example.com/b"}',
                '<p><a href="safe.html" attributionsrc="https://example.com/a https://example.com/b">y</a></p>',
            ],
        ];
    }

    #[DataProvider('cleanListProvider')]
    public function testACleanListIsKeptVerbatim(string $carve, string $expected): void
    {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * THE COMMA HALF OF THE SEPARATOR RULE, for the two names that have it.
     *
     * With the space after the comma absent, a whitespace-only split reads
     * `1x,javascript:alert(1)` as ONE token whose leading scheme is `1x`, and
     * the second candidate hides inside the first one's descriptor. Drop the
     * comma from either name's separator class and only this test notices.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function commaSeparatedCandidateProvider(): array
    {
        return [
            'srcset' => [
                '![a](safe.png){srcset="safe.png 1x,javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" srcset="">',
            ],
            'imagesrcset' => [
                '![a](safe.png){imagesrcset="safe.png 1x,javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" imagesrcset="">',
            ],
        ];
    }

    #[DataProvider('commaSeparatedCandidateProvider')]
    public function testACommaEndsACandidateForTheImageCandidateAttributes(
        string $carve,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * THE WHITESPACE-ONLY HALF, for the two names that must NOT split on a
     * comma.
     *
     * Neither grammar has a comma in it, so treating one as a separator would
     * blank a single legitimate URL that merely carries a comma in its path.
     * This is a false positive, and false positives are the binding constraint
     * on the rule's shape. Unify the two separator classes and only this test
     * notices.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function commaInsideALoneUrlProvider(): array
    {
        return [
            'ping' => [
                '[y](safe.html){ping="https://example.com/a,data:x"}',
                '<p><a href="safe.html" ping="https://example.com/a,data:x">y</a></p>',
            ],
            'attributionsrc' => [
                '[y](safe.html){attributionsrc="https://example.com/a,data:x"}',
                '<p><a href="safe.html" attributionsrc="https://example.com/a,data:x">y</a></p>',
            ],
        ];
    }

    #[DataProvider('commaInsideALoneUrlProvider')]
    public function testACommaInsideALoneUrlDoesNotSplitASpaceSeparatedSet(
        string $carve,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * The one shape the comma split over-blanks, pinned so it cannot drift.
     *
     * `https://example.com/a,data:x 1x` is ONE candidate to a consumer and is
     * blanked here anyway. Reading it exactly would mean requiring the HTML
     * candidate-list algorithm from three engines that have to agree byte for
     * byte. An implementation that gets this "right" has diverged.
     */
    public function testTheCommaSplitOverBlanksOneShapeDeliberately(): void
    {
        $this->assertSame(
            '<img src="safe.png" alt="a" srcset="">',
            $this->render('![a](safe.png){srcset="https://example.com/a,data:x 1x"}'),
        );
    }

    /**
     * ASCII whitespace other than a plain space separates too.
     *
     * A separator class narrowed to `' '` alone passes every other test here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function asciiWhitespaceSeparatorProvider(): array
    {
        return [
            'tab' => ["[y](safe.html){ping=\"safe.html\tjavascript:alert(1)\"}", 'ping'],
            'form feed' => ["[y](safe.html){ping=\"safe.html\u{000C}javascript:alert(1)\"}", 'ping'],
        ];
    }

    #[DataProvider('asciiWhitespaceSeparatorProvider')]
    public function testEveryAsciiWhitespaceCharacterSeparates(string $carve, string $name): void
    {
        $this->assertSame(
            '<p><a href="safe.html" ' . $name . '="">y</a></p>',
            $this->render($carve),
        );
    }

    /**
     * The name match is case-insensitive, like the `on` prefix in the same
     * clause, and the element still carries the AUTHOR'S spelling.
     *
     * Matching the exact bytes would leave `SRCSET` unprobed, which is the
     * whole reason this is written down.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mixedCaseNameProvider(): array
    {
        return [
            'SRCSET' => [
                '![a](safe.png){SRCSET="safe.png 1x, javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" SRCSET="">',
            ],
            'ImageSrcSet' => [
                '![a](safe.png){ImageSrcSet="safe.png 1x, javascript:alert(1) 2x"}',
                '<img src="safe.png" alt="a" ImageSrcSet="">',
            ],
            'PiNg' => [
                '[y](safe.html){PiNg="safe.html javascript:alert(1)"}',
                '<p><a href="safe.html" PiNg="">y</a></p>',
            ],
            'AttributionSrc' => [
                '[y](safe.html){AttributionSrc="safe.html javascript:alert(1)"}',
                '<p><a href="safe.html" AttributionSrc="">y</a></p>',
            ],
        ];
    }

    #[DataProvider('mixedCaseNameProvider')]
    public function testTheNameMatchIsCaseInsensitive(string $carve, string $expected): void
    {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * The probe is the one `href` and `src` get, so it denies the whole
     * denylist at every position and not just the script-bearing head of it.
     */
    public function testTheTokenProbeCarriesTheWholeSchemeDenylist(): void
    {
        $this->assertSame(
            '<img src="safe.png" alt="a" srcset="">',
            $this->render('![a](safe.png){srcset="safe.png 1x, ms-msdt:x 2x"}'),
        );
        $this->assertSame(
            '<p><a href="safe.html" ping="">y</a></p>',
            $this->render('[y](safe.html){ping="safe.html vbscript:alert(1)"}'),
        );
    }

    /**
     * THE STRIP RUNS PER TOKEN, not once at the front of the value, so the
     * clause's obfuscation reasoning composes instead of being bypassed.
     */
    public function testTheStripRunsPerTokenSoAnObfuscatedCandidateStillBlanks(): void
    {
        $this->assertSame(
            '<img src="safe.png" alt="a" srcset="">',
            $this->render(
                '![a](safe.png){srcset="safe.png 1x, ' . self::NNBSP . 'javascript:alert(1) 2x"}',
            ),
        );
    }

    /**
     * The `Cf` decision composes unchanged: a token beginning with a
     * zero-width space is left alone at EVERY position, for the reason already
     * recorded on the probe (it fails WHATWG URL parsing and lands inert).
     */
    public function testAZeroWidthSpaceIsLeftAloneAtANonLeadingPositionToo(): void
    {
        $value = 'safe.png 1x, ' . self::ZWSP . 'javascript:alert(1) 2x';
        $this->assertSame(
            '<img src="safe.png" alt="a" srcset="' . $value . '">',
            $this->render('![a](safe.png){srcset="' . $value . '"}'),
        );
    }

    /**
     * THE WHITESPACE THE STRIP REMOVES IS WIDER THAN THE WHITESPACE THE SPLIT
     * BREAKS ON, and deliberately so.
     *
     * Both grammars put their boundaries at ASCII whitespace, so
     * `a<U+202F>javascript:x` is ONE token to a consumer and resolves as a
     * relative URL rather than a navigation. Splitting on `\s` or on `\p{Z}`
     * would make it two tokens and blank a value no consumer would fetch.
     */
    public function testUnicodeWhitespaceDoesNotSplitATokenEvenThoughItIsStripped(): void
    {
        $value = 'https://example.com/a' . self::NNBSP . 'javascript:x';
        $this->assertSame(
            '<p><a href="safe.html" ping="' . $value . '">y</a></p>',
            $this->render('[y](safe.html){ping="' . $value . '"}'),
        );
    }

    /**
     * Empty tokens are skipped, so a separator run or a leading/trailing
     * separator cannot blank a clean value on its own.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function emptyTokenProvider(): array
    {
        return [
            'srcset' => [
                '  safe.png 1x,,  other.png 2x  ',
                'srcset',
            ],
            'ping' => [
                '  https://example.com/a   https://example.com/b  ',
                'ping',
            ],
        ];
    }

    #[DataProvider('emptyTokenProvider')]
    public function testEmptyTokensDoNotBlankACleanValue(string $value, string $name): void
    {
        $carve = $name === 'srcset'
            ? '![a](safe.png){' . $name . '="' . $value . '"}'
            : '[y](safe.html){' . $name . '="' . $value . '"}';
        $this->assertStringContainsString($name . '="' . $value . '"', $this->render($carve));
    }

    /**
     * THE OTHER DIRECTION, and the reason the rule names four attributes
     * instead of testing every value for an embedded scheme.
     *
     * A prose attribute is NOT in the set and MUST NOT be tokenized. Each of
     * these carries a colon the way ordinary text does, and the middle one
     * carries a literal `javascript:` in a non-leading position: tokenizing
     * prose would refuse all three. This is the test that stops the next
     * person widening the set when the rule fires spuriously.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function proseAttributeProvider(): array
    {
        return [
            'title with a colon' => [
                '[z](safe.html){title="See: RFC 3986, http://example.com"}',
                '<p><a href="safe.html" title="See: RFC 3986, http://example.com">z</a></p>',
            ],
            'title naming a scheme' => [
                '[z](safe.html){title="never write javascript:alert(1) in a link"}',
                '<p><a href="safe.html" title="never write javascript:alert(1) in a link">z</a></p>',
            ],
            'alt with a scheme word' => [
                '[z](safe.html){alt="see data:x here"}',
                '<p><a href="safe.html" alt="see data:x here">z</a></p>',
            ],
            'aria-label with a ratio' => [
                '[z](safe.html){aria-label="ratio 1:2, see data:x"}',
                '<p><a href="safe.html" aria-label="ratio 1:2, see data:x">z</a></p>',
            ],
        ];
    }

    #[DataProvider('proseAttributeProvider')]
    public function testAProseAttributeIsNotTokenized(string $carve, string $expected): void
    {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * A prose attribute still keeps the LEADING-scheme rule, so widening the
     * negative direction above into "prose is never probed" is caught.
     */
    public function testAProseAttributeStillBlanksALeadingDangerousScheme(): void
    {
        $this->assertSame(
            '<p><a href="safe.html" title="">z</a></p>',
            $this->render('[z](safe.html){title="javascript:alert(1)"}'),
        );
    }

    /**
     * THE TOKEN PASS IS ADDED TO THE VALUE-WIDE PROBE, NOT SUBSTITUTED FOR IT,
     * and this is the row that proves it.
     *
     * A whitespace-separated scheme is the case where the two passes disagree
     * in the dangerous direction. `java script:alert(1)` splits into `java` and
     * `script:alert(1)`; NEITHER token is a dangerous scheme, so a token-ONLY
     * implementation lets it through - while the value-wide probe blanks it,
     * because its strip removes the very space the whitespace split just
     * treated as a boundary. Token-only therefore denies strictly LESS than
     * this engine denied before the rule landed, which would be a security
     * regression shipped as a security fix.
     *
     * THE CORPUS CANNOT CATCH THIS. A token-only implementation passes all ten
     * of the pinned documents and every other document in the suite, so nothing
     * in the conformance run would report three engines diverging on a security
     * boundary. That is what this test is for: a future refactor to token-only
     * fails here, loudly, rather than silently (markup-carve/carve-js#1164).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whitespaceSplitSchemeProvider(): array
    {
        return [
            'srcset' => [
                '![a](safe.png){srcset="java script:alert(1)"}',
                '<img src="safe.png" alt="a" srcset="">',
            ],
            'imagesrcset' => [
                '![a](safe.png){imagesrcset="java script:alert(1)"}',
                '<img src="safe.png" alt="a" imagesrcset="">',
            ],
            'ping' => [
                '[y](safe.html){ping="java script:alert(1)"}',
                '<p><a href="safe.html" ping="">y</a></p>',
            ],
            'attributionsrc' => [
                '[y](safe.html){attributionsrc="java script:alert(1)"}',
                '<p><a href="safe.html" attributionsrc="">y</a></p>',
            ],
            'ping with a tab' => [
                "[y](safe.html){ping=\"java\tscript:alert(1)\"}",
                '<p><a href="safe.html" ping="">y</a></p>',
            ],
        ];
    }

    #[DataProvider('whitespaceSplitSchemeProvider')]
    public function testASchemeSplitByWhitespaceIsStillBlankedInAUrlList(
        string $carve,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->render($carve));
    }

    /**
     * The same value on a PROSE attribute, which is what the URL-list names
     * must not fall behind.
     *
     * If this passes and the provider above fails, the token pass replaced the
     * value-wide probe instead of joining it, and a URL-list attribute is now
     * more permissive than `title`.
     */
    public function testTheProseAttributeBlanksTheSameSplitScheme(): void
    {
        $this->assertSame(
            '<p><a href="safe.html" title="">z</a></p>',
            $this->render('[z](safe.html){title="java script:alert(1)"}'),
        );
    }
}
