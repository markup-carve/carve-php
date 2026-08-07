<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ONE WHITESPACE DEFINITION, IN EVERY CONSTRUCT (PART 7, ruled in
 * markup-carve/carve#963 and written in markup-carve/carve#977).
 *
 * The whitespace characters Carve has are exactly four - U+0020, U+0009,
 * U+000A and U+000D - and EVERY OTHER CHARACTER IS CONTENT. The clause names
 * the two an implementation is likeliest to admit by accident so their absence
 * cannot be read as an oversight: a VERTICAL TAB (U+000B) is CONTENT and a
 * FORM FEED (U+000C) is CONTENT.
 *
 * TWO CHARACTERS, ONE ANSWER, is what these cases assert. The host language
 * offers two wider classes and they disagree with each other as readily as
 * with the production: PHP's default trim charlist takes a VERTICAL TAB but
 * not a form feed, and PCRE reads a FORM FEED as `\s` and a vertical tab too.
 * So a construct spelled with `trim()` answered one way and a construct
 * spelled with `\S` answered the other, and the two characters came apart
 * inside the same engine. Every case below pins them together AND against a
 * plain content character.
 *
 * The characters are built from `chr()` rather than written literally: three
 * probe files silently lost a literal U+000B while this was being measured and
 * produced three wrong readings before a hexdump caught it. {@see
 * self::testTheProbeCharactersSurvivedThisFile} asserts the bytes before any
 * case is trusted.
 */
class OneWhitespaceDefinitionInEveryConstructTest extends TestCase
{
    /**
     * @var string
     */
    private const VERTICAL_TAB = "\x0B";

    /**
     * @var string
     */
    private const FORM_FEED = "\x0C";

    /**
     * The layout collapse is spelled `[ \t\r\n]+`, NOT `\s+`.
     *
     * PCRE reads a VERTICAL TAB and a FORM FEED as `\s`, so `\s+` here would
     * destroy the character under test on its way to the assertion, and every
     * case would pass whatever the parser did. The trim charlist is spelled
     * out for the same reason - PHP's default takes a vertical tab.
     */
    private function html(string $source): string
    {
        $html = trim((new CarveConverter())->convert($source), " \t\r\n");

        return (string)preg_replace('/[ \t\r\n]+/', ' ', $html);
    }

    /**
     * A source template with one `%s` where the probed character goes, and the
     * substring the output must hold once the character is put back in.
     *
     * @return array<string, array{string, string}>
     */
    public static function constructProvider(): array
    {
        return [
            // The continuation marker takes NO RUN AT ALL: `continuation_marker
            // = '+', newline`, so any character between the `+` and the line
            // end is content and the line is not a marker.
            'continuation marker' => ["- a\n\n+%s\n\n  b\n", '<p>+%s</p>'],
            'bullet dash' => ["- %s\n", '<li>%s</li>'],
            'bullet star' => ["* %s\n", '<li>%s</li>'],
            'ordered dot' => ["1. %s\n", '<li>%s</li>'],
            'ordered paren' => ["1) %s\n", '<li>%s</li>'],
            'roman' => ["i. %s\n", '<li>%s</li>'],
            'alpha' => ["a. %s\n", '<li>%s</li>'],
            'task item' => ["- [ ] %s\n", 'disabled> %s</li>'],
            'definition term' => [":: %s\n:  d\n", '<dt>%s</dt>'],
            'definition description' => [":: t\n:  %s\n", '<dd>%s</dd>'],
            'footnote definition' => ["x[^a]\n\n[^a]: %s\n", '<p>%s<a href="#fnref1"'],
            'abbreviation definition' => ["*[A]: %s\n\nA\n", 'title="%s"'],
            'heading' => ["# %s\n", '<h1>%s</h1>'],
            'caption' => ["| a |\n^ %s\n", '<caption>%s</caption>'],
            'paragraph trailing' => ["a%s\n", '<p>a%s</p>'],
            'a line holding one is not blank' => ["a\n%s\nb\n", '<p>a %s b</p>'],
            // The block-attribute line takes `opt_ws`, and a character that is
            // NOT whitespace after the closing brace is trailing CONTENT - so
            // the line is not an attribute line and stays a paragraph.
            'block attribute line' => ["{#x}%s\np\n", '}%s p</p>'],
        ];
    }

    #[DataProvider('constructProvider')]
    public function testAVerticalTabIsContent(string $template, string $expected): void
    {
        $this->assertStringContainsString(
            sprintf($expected, self::VERTICAL_TAB),
            $this->html(sprintf($template, self::VERTICAL_TAB)),
        );
    }

    #[DataProvider('constructProvider')]
    public function testAFormFeedIsContent(string $template, string $expected): void
    {
        $this->assertStringContainsString(
            sprintf($expected, self::FORM_FEED),
            $this->html(sprintf($template, self::FORM_FEED)),
        );
    }

    /**
     * The two characters must give the SAME answer, which is the half a
     * per-character expectation cannot state. A construct that admits one and
     * refuses the other is reading its host language rather than the grammar,
     * and that is exactly how carve-php came to hold both answers at once.
     */
    #[DataProvider('constructProvider')]
    public function testTheTwoCharactersAgreeWithEachOther(string $template, string $expected): void
    {
        $withVerticalTab = str_replace(self::VERTICAL_TAB, 'C', $this->html(sprintf($template, self::VERTICAL_TAB)));
        $withFormFeed = str_replace(self::FORM_FEED, 'C', $this->html(sprintf($template, self::FORM_FEED)));

        $this->assertSame($withVerticalTab, $withFormFeed, 'unused: ' . $expected);
    }

    /**
     * And they must give the same answer as a character nobody has ever called
     * whitespace, modulo the character itself. This is the case that would
     * have caught the whole class: it needs no expectation written per
     * construct, only the claim that the character is content.
     *
     * `heading` and `task item` are excluded because the output holds a value
     * DERIVED from the content rather than the content: a heading's generated
     * id and a task item's checkbox state both differ for a control character
     * for reasons that are not about whitespace.
     */
    #[DataProvider('constructProvider')]
    public function testEachCharacterBehavesLikeAnOrdinaryContentCharacter(string $template, string $expected): void
    {
        if (str_contains($expected, '<h1>') || str_contains($expected, 'disabled>')) {
            $this->markTestSkipped('output carries a value derived from the content, not the content');
        }

        $reference = $this->html(sprintf($template, 'C'));

        foreach ([self::VERTICAL_TAB, self::FORM_FEED] as $character) {
            $this->assertSame(
                $reference,
                str_replace($character, 'C', $this->html(sprintf($template, $character))),
                'differs from a plain content character',
            );
        }
    }

    /**
     * A CODE FENCE'S INFO STRING, which is not in the provider because the
     * output carries no copy of the character to assert on: a non-identifier
     * info string refuses the fence outright, so what has to be pinned is that
     * the two characters REACH the info string at all and are refused there
     * together.
     *
     * The trailing trim on that slot used PHP's default charlist, so a vertical
     * tab was eaten and the fence opened with an EMPTY info string - a code
     * block where a form feed, an exclamation mark and every other
     * non-identifier character in the same slot produced an inline verbatim
     * span in a paragraph.
     */
    public function testAControlCharacterInAnInfoStringIsRefusedLikeAnyOtherNonIdentifier(): void
    {
        $fence = str_repeat('`', 3);
        $reference = $this->html($fence . "!\nx\n" . $fence . "\n");

        foreach ([self::VERTICAL_TAB, self::FORM_FEED] as $character) {
            $this->assertSame(
                $reference,
                str_replace($character, '!', $this->html($fence . $character . "\nx\n" . $fence . "\n")),
                'an info string of one control character opened a code block',
            );
        }
    }

    /**
     * The slot itself still takes real whitespace: an EMPTY info string opens a
     * plain code block, and one space before a language still names it.
     */
    public function testTheInfoStringSlotStillTakesRealWhitespace(): void
    {
        $fence = str_repeat('`', 3);

        $this->assertStringContainsString('<pre><code>x', $this->html($fence . "\nx\n" . $fence . "\n"));
        $this->assertStringContainsString('language-php', $this->html($fence . " php\nx\n" . $fence . "\n"));
        $this->assertStringContainsString('<pre><code>x', $this->html($fence . " \nx\n" . $fence . "\n"));
    }

    /**
     * A trailing run of REAL whitespace is still whitespace, and the marker
     * gates still refuse a marker whose content is only that. Narrowing the
     * definition must not widen what counts as content.
     */
    public function testARealWhitespaceRunIsStillWhitespace(): void
    {
        $this->assertStringContainsString('<p>-</p>', $this->html("- \n"));
        $this->assertStringContainsString('<p>1.</p>', $this->html("1. \t\n"));
        $this->assertStringNotContainsString('<dl>', $this->html(":: \t\n:  d\n"));
        $this->assertStringContainsString('<p>a</p> <p>b</p>', $this->html("a\n \t\nb\n"));
    }

    /**
     * The continuation marker still works with a trailing run of whitespace,
     * which is what its docblock's `/^\+[ \t]*$/` says.
     */
    public function testTheContinuationMarkerStillTakesATrailingWhitespaceRun(): void
    {
        foreach (['', ' ', "\t", " \t "] as $run) {
            $this->assertStringContainsString(
                '<p>a</p> <p>b</p>',
                $this->html("- a\n\n+" . $run . "\n\n  b\n"),
                'run: ' . json_encode($run),
            );
        }
    }

    /**
     * BUILD THE CHARACTER FROM AN ESCAPE AND ASSERT THE BYTE IS PRESENT before
     * trusting any measurement. A probe file can silently lose the character it
     * is probing, and a case that lost it passes for the wrong reason.
     */
    public function testTheProbeCharactersSurvivedThisFile(): void
    {
        $this->assertSame(1, strlen(self::VERTICAL_TAB));
        $this->assertSame(11, ord(self::VERTICAL_TAB));
        $this->assertSame(1, strlen(self::FORM_FEED));
        $this->assertSame(12, ord(self::FORM_FEED));
    }
}
