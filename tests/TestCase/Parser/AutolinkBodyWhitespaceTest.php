<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `url_autolink` body admits no UNICODE whitespace, only ASCII.
 *
 * `<https://e<U+00A0>.com/>` used to render as a link whose href carried an
 * invisible character. The body pattern excluded whitespace with `\s` and no
 * `u` modifier, so it was a byte test and a NO-BREAK SPACE walked straight
 * through it - the same legacy ASCII reading that carve#404 fixed for a link
 * destination, one production over.
 *
 * WHY THIS NEEDS NO RULING. `url_char` (`resources/grammar.ebnf`) enumerates
 * ASCII, so under a strict reading no non-ASCII character reaches the body at
 * all. `unicode_url_char`, the only production admitting anything wider, is
 * "any NON-WHITESPACE, non-ASCII Unicode character", and its normative note
 * says that means the Unicode White_Space property. A NO-BREAK SPACE is
 * therefore excluded under both readings of markup-carve/carve#860, whichever
 * way that ticket's own question is eventually settled.
 *
 * WHAT IS DELIBERATELY UNCHANGED. That question - whether `url_autolink` should
 * admit `unicode_url_char` at all - is open, so this engine's answer for a
 * NON-whitespace non-ASCII character is left exactly as it was. The controls
 * below pin it, so a later widening of this rule cannot answer that ticket by
 * accident.
 */
class AutolinkBodyWhitespaceTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    /**
     * Every non-ASCII character carrying the Unicode White_Space property.
     *
     * @return array<string, array{string}>
     */
    public static function unicodeWhitespaceProvider(): array
    {
        return [
            'next line' => ["\u{0085}"],
            'no-break space' => ["\u{00A0}"],
            'ogham space mark' => ["\u{1680}"],
            'en quad' => ["\u{2000}"],
            'em quad' => ["\u{2001}"],
            'en space' => ["\u{2002}"],
            'em space' => ["\u{2003}"],
            'three-per-em space' => ["\u{2004}"],
            'four-per-em space' => ["\u{2005}"],
            'six-per-em space' => ["\u{2006}"],
            'figure space' => ["\u{2007}"],
            'punctuation space' => ["\u{2008}"],
            'thin space' => ["\u{2009}"],
            'hair space' => ["\u{200A}"],
            'line separator' => ["\u{2028}"],
            'paragraph separator' => ["\u{2029}"],
            'narrow no-break space' => ["\u{202F}"],
            'medium mathematical space' => ["\u{205F}"],
            'ideographic space' => ["\u{3000}"],
        ];
    }

    #[DataProvider('unicodeWhitespaceProvider')]
    public function testItDoesNotAutolinkAHostCarryingIt(string $space): void
    {
        $html = $this->converter->convert("<https://e{$space}.com/>\n");

        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * Not "rejected in the host" - the body ENDS at whitespace wherever it sits,
     * which is what the destination rule already says. A check anchored to one
     * position would pass the row the ticket measured and miss the rest.
     *
     * @return array<string, array{string}>
     */
    public static function positionProvider(): array
    {
        $space = "\u{00A0}";

        return [
            'immediately after the scheme colon' => ["<https:{$space}//e.com/>"],
            'inside the host' => ["<https://e{$space}.com/>"],
            'inside the path' => ["<https://e.com/a{$space}b>"],
            'immediately before the closing bracket' => ["<https://e.com/{$space}>"],
            'the whole body after the scheme' => ["<https:{$space}>"],
        ];
    }

    #[DataProvider('positionProvider')]
    public function testTheBodyEndsAtWhitespaceWhereverItSits(string $source): void
    {
        $this->assertStringNotContainsString('<a ', $this->converter->convert($source . "\n"));
    }

    /**
     * BOTH directions of a run of two DIFFERENT Unicode spaces. A check that
     * looked at one end of a whitespace run, or at its first character, would
     * pass a one-sided fixture and still link the other order.
     *
     * @return array<string, array{string}>
     */
    public static function mixedRunProvider(): array
    {
        $nbsp = "\u{00A0}";
        $thin = "\u{2009}";
        $ideographic = "\u{3000}";

        return [
            'no-break space then thin space' => ["<https://e{$nbsp}{$thin}.com/>"],
            'thin space then no-break space' => ["<https://e{$thin}{$nbsp}.com/>"],
            'no-break space then ideographic space' => ["<https://e{$nbsp}{$ideographic}.com/>"],
            'ideographic space then no-break space' => ["<https://e{$ideographic}{$nbsp}.com/>"],
            'no-break space around a thin space' => ["<https://e{$nbsp}{$thin}{$nbsp}.com/>"],
            'a unicode space either side of the dot' => ["<https://e{$nbsp}.{$thin}com/>"],
        ];
    }

    #[DataProvider('mixedRunProvider')]
    public function testAMixedWhitespaceRunBreaksTheBodyFromEitherEnd(string $source): void
    {
        $this->assertStringNotContainsString('<a ', $this->converter->convert($source . "\n"));
    }

    /**
     * CONTROLS. A run mixing ASCII whitespace with a Unicode space was already
     * refused before this change, because the byte pattern excludes the ASCII
     * one on its own - including the VERTICAL TAB, which `trim()`'s default
     * charlist and PCRE's `\s` happen to agree on here but which is the exact
     * character that has slipped past a guard in this engine before.
     *
     * They are recorded rather than dropped: reverting the fix does not fail
     * any of them, so they prove nothing about the new rule and are labeled
     * accordingly, but a future change that narrows the BYTE pattern instead
     * would show up here and nowhere else.
     *
     * @return array<string, array{string}>
     */
    public static function asciiMixedRunControlProvider(): array
    {
        $nbsp = "\u{00A0}";

        return [
            'ascii space then no-break space' => ["<https://e {$nbsp}.com/>"],
            'no-break space then ascii space' => ["<https://e{$nbsp} .com/>"],
            'tab then no-break space' => ["<https://e\t{$nbsp}.com/>"],
            'no-break space then tab' => ["<https://e{$nbsp}\t.com/>"],
            'vertical tab then no-break space' => ["<https://e\x0B{$nbsp}.com/>"],
            'no-break space then vertical tab' => ["<https://e{$nbsp}\x0B.com/>"],
        ];
    }

    #[DataProvider('asciiMixedRunControlProvider')]
    public function testAnAsciiWhitespaceRunAlreadyBrokeTheBody(string $source): void
    {
        $this->assertStringNotContainsString('<a ', $this->converter->convert($source . "\n"));
    }

    /**
     * The closer scan carries its own copy of this judgment: it skips over an
     * autolink so a delimiter inside one cannot close a run around it. A body
     * this rule rejects is not an autolink there either, so the `*` inside it
     * closes the emphasis - which is what the other two engines already do.
     */
    public function testTheCloserScanAgreesWithTheParser(): void
    {
        $nbsp = "\u{00A0}";

        $this->assertSame(
            "<p><strong>a &lt;http://e&nbsp;.com/</strong>&gt; b*</p>\n",
            $this->converter->convert("*a <http://e{$nbsp}.com/*> b*\n"),
            'measured byte-identical on carve-js and carve-rs',
        );
    }

    /**
     * CONTROLS. Each pins something this fix must NOT have moved.
     *
     * @return array<string, array{string, bool}>
     */
    public static function controlProvider(): array
    {
        return [
            'a plain ascii autolink still links' => ['<https://e.com/>', true],
            'whitespace outside the brackets does not reach the body'
                => ["<https://e.com/>\u{00A0}x", true],
            'zero-width space is not whitespace (carve#860, open)'
                => ["<https://e\u{200B}.com/>", true],
            'byte order mark is not whitespace (carve#860, open)'
                => ["<https://e\u{FEFF}.com/>", true],
            'an idn host (carve#860, open)' => ["<https://\u{4F8B}.jp/>", true],
            'mongolian vowel separator is not White_Space (carve#860, open)'
                => ["<https://e\u{180E}.com/>", true],
            'word joiner is not whitespace (carve#860, open)'
                => ["<https://e\u{2060}.com/>", true],
            'an ascii space already broke the body' => ['<https://e .com/>', false],
        ];
    }

    #[DataProvider('controlProvider')]
    public function testTheRulesAroundThisOneAreUnchanged(string $source, bool $links): void
    {
        $html = $this->converter->convert($source . "\n");

        if ($links) {
            $this->assertStringContainsString('<a ', $html);

            return;
        }

        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * A subject that is not valid UTF-8 makes the Unicode pattern return false
     * rather than 0. Reading that as "whitespace found" would refuse an
     * autolink over a malformed-input case no clause covers, so the byte
     * pattern's verdict stands and the run still links.
     */
    public function testAMalformedByteSequenceIsJudgedByTheBytePatternAlone(): void
    {
        $this->assertStringContainsString(
            '<a ',
            $this->converter->convert("<https://e\xFF\xFE.com/>\n"),
        );
    }
}
