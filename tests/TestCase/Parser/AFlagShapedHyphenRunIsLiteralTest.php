<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §8, A FLAG-SHAPED HYPHEN RUN IS LITERAL (markup-carve/carve#1443).
 *
 * A run PRECEDED by whitespace (or the start of the content) and FOLLOWED by a
 * non-whitespace character is a long CLI flag, not a dash. The failure it
 * repairs was silent and output-only: the author saw `git log --oneline` in the
 * source and the reader got a command that does not run.
 *
 * The narrowness is the design. Every canonical dash use is unspaced on at
 * least one side, so a rule keyed on whitespace-both-sides would have removed
 * the feature with the damage, and one keyed on sides-matching-in-kind would
 * have broken `a---- b----- c------`, which the corpus pins.
 */
class AFlagShapedHyphenRunIsLiteralTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function literalProvider(): array
    {
        return [
            'long flag mid-sentence' => ["git log --oneline\n", "<p>git log --oneline</p>\n"],
            'long flag with hyphens in the name' => ["use --force-with-lease\n", "<p>use --force-with-lease</p>\n"],
            'flag at the start of the content' => ["--force x\n", "<p>--force x</p>\n"],
            // `-->` is the CANONICAL rightwards arrow since markup-carve/carve#1442,
            // and an arrow is matched before a hyphen run is decomposed - so this
            // clause never reaches it. The ruling is deliberate: guarding `-->`
            // for the HTML-comment context would put a context-sensitive
            // exception into a set whose whole argument is that it has none.
            'the closing half of an HTML comment is an arrow' => ["x -->\n", "<p>x \u{2192}</p>\n"],
            'a longer run keeps every hyphen' => ["x ---foo\n", "<p>x ---foo</p>\n"],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('literalProvider')]
    public function testAFlagShapedRunStaysLiteral(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array<string>>
     */
    public static function convertingProvider(): array
    {
        return [
            'numeric range' => ["pages 1--10\n", "<p>pages 1\u{2013}10</p>\n"],
            'day range' => ["the Mon--Fri window\n", "<p>the Mon\u{2013}Fri window</p>\n"],
            'interrupted clause' => ["a thought---interrupted---resumes\n", "<p>a thought\u{2014}interrupted\u{2014}resumes</p>\n"],
            'spaced on both sides' => ["a -- b\n", "<p>a \u{2013} b</p>\n"],
            'trailing run' => ["text --\n", "<p>text \u{2013}</p>\n"],
            // The corpus pins this one, and it is the case that kills a rule
            // requiring the two sides to match in kind.
            'mixed run lengths' => ["a---- b----- c------\n", "<p>a\u{2013}\u{2013} b\u{2014}\u{2013} c\u{2014}\u{2014}</p>\n"],
            // NEITHER half survives, and each is lost to a different rule: the
            // opening run is preceded by `!` rather than whitespace, so this
            // clause does not reach it and it converts to a dash; the closing run
            // is the canonical rightwards arrow (markup-carve/carve#1442).
            'html comment' => ["<!-- c -->\n", "<p>&lt;!\u{2013} c \u{2192}</p>\n"],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('convertingProvider')]
    public function testEveryOtherPositionStillConverts(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The flanking test reads PART 7's whitespace, not one of PHP's three
     * classes: `\s` and `ctype_space()` both take a VERTICAL TAB and a FORM
     * FEED, which Carve reads as CONTENT.
     *
     * @return void
     */
    public function testTheSpaceClassIsPartSevens(): void
    {
        foreach (['!', "\x0B", "\x0C"] as $probe) {
            $this->assertSame(
                '<p>---' . $probe . "</p>\n",
                $this->html('---' . $probe . "\n"),
                'a content character behaved like a space',
            );
        }
    }

    /**
     * A NO-BREAK SPACE is a space to the reader, in both of its spellings.
     *
     * @return void
     */
    public function testANoBreakSpaceIsASpace(): void
    {
        $this->assertSame("<p>a&nbsp;--foo</p>\n", $this->html("a\u{00a0}--foo\n"));
        $this->assertSame("<p>a&nbsp;--foo</p>\n", $this->html("a\\ --foo\n"));
    }

    /**
     * A literal run is written back as the hyphens the author wrote.
     *
     * @return void
     */
    public function testTheWriterKeepsTheHyphens(): void
    {
        $source = "git log --oneline\n";
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($source, $formatted);
        $this->assertSame($this->html($source), $this->html($formatted));
    }
}
