<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 7, ONE WHITESPACE DEFINITION, IN EVERY CONSTRUCT (markup-carve/carve#963,
 * markup-carve/carve#977).
 *
 * Whitespace is U+0020, U+0009, U+000A and U+000D. Every other C0 control is
 * CONTENT, VERTICAL TAB (U+000B) and FORM FEED (U+000C) included.
 *
 * `markup-carve/carve-php#1040` did the heading and the caption and
 * `markup-carve/carve-php#1046` did twenty-five marker and emptiness predicates.
 * This is the rest of the engine: the sites those two did not reach, found by
 * MEASUREMENT rather than by reading the ticket's table.
 *
 * THE METHOD, AND WHY THE BASELINE IS NOT A LETTER. Each construct is fed a
 * vertical tab, a form feed and an ORDINARY CONTENT CHARACTER, and the rows kept
 * are the ones where the first two do not behave like the third. The baseline
 * has to be a character that is content but NOT a word character: with `Z` half
 * these rows report a false divergence, because `Z` extends an identifier - it
 * makes `#aZ` a longer id and ```` ```=htmlZ ```` a different format token -
 * where a vertical tab cannot. U+0001 is worse: the HTML target sanitizes it
 * away, which made every row look divergent on the first run of this sweep. `!`
 * is content, is not a word character and survives to the output.
 *
 * THE THREE CLASSES PHP OFFERS ARE WRONG IN DIFFERENT DIRECTIONS, which is why
 * one sweep does not find the next one's sites:
 *
 * - PCRE `\s` takes a vertical tab AND a form feed.
 * - PHP's default `trim()` charlist takes a vertical tab and NOT a form feed.
 * - `ctype_space()` takes both.
 *
 * So a construct spelled with `trim()` and a construct spelled with `\s` gave
 * two different answers inside the same engine, and the row where the two probe
 * characters disagreed with EACH OTHER is the one that proves at least one of
 * them wrong without needing an expectation written per construct.
 *
 * BUILD THE CHARACTER FROM AN ESCAPE. Probe files in three earlier sessions
 * silently lost a literal U+000B and produced confident wrong readings, so these
 * cases build it from `"\v"` / `"\x0B"` and {@see self::testTheProbeBytesAreWhatTheyClaim()}
 * asserts the code point before any other case is trusted.
 */
class OneWhitespaceDefinitionInTheRemainingConstructsTest extends TestCase
{
    /**
     * @var string
     */
    protected const VT = "\x0B";

    /**
     * @var string
     */
    protected const FF = "\x0C";

    /**
     * An ordinary CONTENT character: not whitespace, not a word character, not
     * stripped by any target.
     *
     * @var string
     */
    protected const BASE = '!';

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * Render with the probe character substituted for `@`, then fold that
     * character to a sentinel so only the STRUCTURE is compared.
     *
     * @param string $template
     * @param string $probe
     *
     * @return string
     */
    protected function shape(string $template, string $probe): string
    {
        $rendered = $this->html(str_replace('@', $probe, $template));

        return str_replace($probe, "\u{2022}", $rendered);
    }

    /**
     * The measurement trap this whole ticket carries a warning about. If a probe
     * character is not the byte it claims to be, every reading below is fiction.
     *
     * @return void
     */
    public function testTheProbeBytesAreWhatTheyClaim(): void
    {
        $this->assertSame(1, strlen(self::VT));
        $this->assertSame(0x0B, ord(self::VT));
        $this->assertSame(1, strlen(self::FF));
        $this->assertSame(0x0C, ord(self::FF));
        $this->assertSame(1, strlen(self::BASE));
        $this->assertSame(0x21, ord(self::BASE));
    }

    /**
     * One row per construct that this sweep moved.
     *
     * @return array<string, array{0: string}>
     */
    public static function constructProvider(): array
    {
        return [
            // Line trailing padding: `\s*$` read the two characters as padding,
            // so the line was still the construct and the character was eaten.
            'code fence closer' => ["```\nx\n```@\n"],
            'div fence closer' => [":::\nx\n:::@\n"],
            'div fence opener' => [":::@\nx\n:::\n"],
            'verse fence opener' => ["::: verse@\na\n:::\n"],
            'raw block fence' => ["```=html@\n<b>x</b>\n```\n"],
            'frontmatter opener' => ["---@\nt: 1\n---\n\nb\n"],
            'table row attributes' => ["| a |{.c}@\n"],
            'table continuation row' => ["| a |\n+ b |@\n"],
            'block image line' => ["![alt](/i.png)@\n"],
            'block attribute line' => ["{#x}@\np\n"],
            'multiline attribute close' => ["{#x\n.y}@\np\n"],
            'reference definition attributes' => ["[a]: /u {.c}@\n\n[a][]\n"],
            'thematic break' => ["---@\n"],
            'line block opener' => ["|@\n| a\n"],

            // Indentation.
            'indent before a thematic break' => ["@---\n"],
            'fence body indent strip' => ["```\n@x\n```\n"],

            // Attribute separators, where the run is INSIDE the block.
            'unquoted attribute value' => ["{k=v@w}\np\n"],
            'separator between attributes' => ["{#a@.b}\np\n"],

            // Inline delimiter flanking, which read `ctype_space()`.
            'emphasis flanking' => ["/@a/\n"],
            'strong flanking' => ["*@a*\n"],
            'bold-italic gates' => ["/*@a*/\n"],

            // Emptiness tests and identifier slots.
            'inline footnote emptiness' => ["x^[@]\n"],
            'cross-reference id' => ["</#a@b>\n\n# H {#a@b}\n"],
            'footnote reference label' => ["x[^a@b]\n\n[^a@b]: n\n"],
            'code span content' => ["`x@`\n"],
            'definition term content' => [":: @\n:  d\n"],
            'comment line separator' => ["%%@c\np\n"],
            'fence info token' => ["```js@x\ncode\n```\n"],
            'paragraph trailing' => ["abc@\n"],
        ];
    }

    /**
     * A VERTICAL TAB and a FORM FEED are CONTENT, so each construct answers the
     * way it answers for an ordinary content character.
     *
     * @param string $template
     *
     * @return void
     */
    #[DataProvider('constructProvider')]
    public function testTheTwoControlsBehaveLikeContent(string $template): void
    {
        $baseline = $this->shape($template, self::BASE);

        $this->assertSame($baseline, $this->shape($template, self::VT), 'vertical tab diverged');
        $this->assertSame($baseline, $this->shape($template, self::FF), 'form feed diverged');
    }

    /**
     * The severe shape, called out separately because its blast radius is not one
     * line. A frontmatter opener runs to the next bare three-dash line, so reading
     * the character as padding does not mislabel the opener - it swallows the
     * document down to the closer.
     *
     * @return void
     */
    public function testAFrontmatterOpenerDoesNotSwallowTheDocument(): void
    {
        $html = $this->html('---' . self::VT . "\nt: 1\n---\n\nb\n");

        $this->assertStringContainsString('t: 1', $html);
        $this->assertNotSame("<p>b</p>\n", $html);
    }

    /**
     * A row where the two probe characters disagreed with EACH OTHER, which is
     * what proves at least one of them wrong without an expectation per
     * construct. `rtrim()`'s default charlist takes a vertical tab and not a form
     * feed, so a continuation row ending in one folded into the cell above while
     * the same row ending in the other became a paragraph between two tables.
     *
     * @return void
     */
    public function testTheTwoControlsAgreeWithEachOtherOnTheContinuationRow(): void
    {
        $template = "| a |\n+ b |@\n";

        $this->assertSame($this->shape($template, self::FF), $this->shape($template, self::VT));
    }

    /**
     * CONTROL, and it must stay wide. PART 3 marks a link destination as taking
     * UNICODE whitespace, which is one of the two notions the clause explicitly
     * does not disturb. Narrow this slot to PART 7's four characters and these
     * two stop terminating the destination.
     *
     * @return void
     */
    public function testALinkDestinationStillEndsAtUnicodeWhitespaceControl(): void
    {
        $this->assertSame("<p>[t](/u\u{2000}x)</p>\n", $this->html("[t](/u\u{2000}x)\n"));
        $this->assertSame("<p>[t](/u&nbsp;x)</p>\n", $this->html("[t](/u\u{00A0}x)\n"));
    }

    /**
     * CONTROL, and it must stay wide. A quote after a NO-BREAK SPACE OPENS - the
     * smart-quote context test carries the no-break space on top of PART 7's four
     * characters, deliberately. Narrowing the context test to exactly the four
     * would curl this one the other way.
     *
     * Its pair is the row below it: the same test now treats a VERTICAL TAB as
     * content, so a quote after one is word-adjacent and CLOSES. The two rows
     * moving in opposite directions is what says the no-break space is carried on
     * purpose rather than left over from the old `ctype_space()` class.
     *
     * @return void
     */
    public function testAQuoteAfterANoBreakSpaceStillOpensControl(): void
    {
        $this->assertSame("<p>a&nbsp;\u{201C}q\u{201D}</p>\n", $this->html("a\u{00A0}\"q\"\n"));

        $this->assertSame(
            '<p>a' . self::VT . "\u{201D}q\u{201D}</p>\n",
            $this->html('a' . self::VT . "\"q\"\n"),
        );
    }
}
