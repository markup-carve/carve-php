<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 3: AN AUTOLINK BODY ADMITS NON-ASCII AND EXCLUDES FORMAT CHARACTERS
 * (markup-carve/carve#844, markup-carve/carve#860).
 *
 * Outside ASCII, `url_char` admits any character that is not whitespace, not a
 * format character (General_Category Cf) and not a control character (Cc).
 *
 * TWO HALVES, and no implementation had both. This engine had the first and not
 * the second: an internationalized domain already linked, and so did a host
 * carrying a byte order mark or a U+0001.
 *
 * BY CODEPOINT, not by readable bytes. The whole Cf property and the control
 * characters cannot be written as text a reader can check, so the corpus can
 * only carry a representative few - `272-...` carries U+FEFF and U+200B. The
 * classes themselves are pinned here, the way the spec repo pins them in
 * `tests/url-char-classes.test.mjs`.
 *
 * THE C1 TRAP HAS ITS OWN ROW. U+0080-U+009F are non-ASCII and, apart from
 * U+0085, non-whitespace - so a rule spelled "non-ASCII and not Cf" admits
 * fourteen control characters while excluding every C0 one, which is not a rule
 * anyone would state on purpose. The executable spec did exactly that until its
 * own class test caught it.
 */
class AutolinkBodyCharacterClassTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    /**
     * Non-ASCII characters that ARE `url_char`s, so the body still links.
     *
     * @return array<string, array{string}>
     */
    public static function admittedProvider(): array
    {
        return [
            'an idn host' => ["https://\u{4F8B}.jp/"],
            'an accented host' => ["https://caf\u{00E9}.fr/"],
            'a non-ascii path' => ["https://example.com/caf\u{00E9}"],
            // NOT A LETTER, and the clause admits it all the same: `url_char`
            // is stated as a property, not as "a letter or a digit".
            'a currency sign' => ["https://example.com/\u{20AC}10"],
            'a cjk comma' => ["https://example.com/a\u{3001}b"],
            'an emoji' => ["https://example.com/\u{1F600}"],
            'a combining mark' => ["https://example.com/e\u{0301}"],
            'an arabic-indic digit' => ["https://example.com/\u{0661}"],
            // A character in the supplementary planes is ordinary: it is not
            // Cf and not Cc, and the clause is stated as a property rather than
            // as a range.
            'a supplementary-plane letter' => ["https://example.com/\u{20000}"],
        ];
    }

    #[DataProvider('admittedProvider')]
    public function testANonAsciiUrlCharStillLinks(string $body): void
    {
        $html = $this->converter->convert('<' . $body . ">\n");

        $this->assertStringContainsString('<a ', $html, 'the body is a run of url_chars');
        $this->assertSame($body, self::hrefOf($html), 'the destination is the body as written');
    }

    /**
     * FORMAT CHARACTERS (Cf). One per block the property spans, so a fix that
     * enumerates a handful of codepoints instead of testing the property fails
     * on the ones it did not think of.
     *
     * @return array<string, array{string}>
     */
    public static function formatCharacterProvider(): array
    {
        return [
            'soft hyphen' => ["\u{00AD}"],
            'arabic number sign' => ["\u{0600}"],
            'syriac abbreviation mark' => ["\u{070F}"],
            'mongolian vowel separator' => ["\u{180E}"],
            'zero width space' => ["\u{200B}"],
            'zero width non-joiner' => ["\u{200C}"],
            'zero width joiner' => ["\u{200D}"],
            'left-to-right mark' => ["\u{200E}"],
            'right-to-left mark' => ["\u{200F}"],
            'left-to-right embedding' => ["\u{202A}"],
            'left-to-right isolate' => ["\u{2066}"],
            'word joiner' => ["\u{2060}"],
            'invisible times' => ["\u{2062}"],
            'byte order mark' => ["\u{FEFF}"],
            'interlinear annotation anchor' => ["\u{FFF9}"],
            'shorthand format letter overlap' => ["\u{1BCA0}"],
            'language tag' => ["\u{E0001}"],
        ];
    }

    #[DataProvider('formatCharacterProvider')]
    public function testAFormatCharacterInTheBodyIsNotAnAutolink(string $character): void
    {
        // A format character is INVISIBLE by definition, so a host carrying one
        // renders as the host without it and links somewhere else. That is a
        // spoofing surface rather than an authoring convenience.
        $html = $this->converter->convert("<https://e{$character}.com/>\n");

        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * CONTROL CHARACTERS (Cc), both blocks.
     *
     * @return array<string, array{string}>
     */
    public static function controlCharacterProvider(): array
    {
        return [
            'c0 start of heading' => ["\u{0001}"],
            'c0 bell' => ["\u{0007}"],
            'c0 escape' => ["\u{001B}"],
            'c0 unit separator' => ["\u{001F}"],
            'delete' => ["\u{007F}"],
            // THE C1 BLOCK, which is non-ASCII and non-Cf, and which a rule
            // spelled "non-ASCII and not a format character" admits.
            'c1 padding character' => ["\u{0080}"],
            'c1 break permitted here' => ["\u{0082}"],
            'c1 device control string' => ["\u{0090}"],
            'c1 application program command' => ["\u{009F}"],
        ];
    }

    #[DataProvider('controlCharacterProvider')]
    public function testAControlCharacterInTheBodyIsNotAnAutolink(string $character): void
    {
        $html = $this->converter->convert("<https://e{$character}.com/>\n");

        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * Every position in the body, for each of the two excluded classes. A
     * check anchored to the host would pass the row the ticket measured and
     * miss the rest.
     *
     * @return array<string, array{string}>
     */
    public static function positionProvider(): array
    {
        $bom = "\u{FEFF}";
        $soh = "\u{0001}";

        return [
            'format character right after the scheme colon' => ["<https:{$bom}//e.com/>"],
            'format character in the host' => ["<https://e{$bom}.com/>"],
            'format character in the path' => ["<https://e.com/a{$bom}b>"],
            'format character before the closing bracket' => ["<https://e.com/{$bom}>"],
            'control character right after the scheme colon' => ["<https:{$soh}//e.com/>"],
            'control character in the host' => ["<https://e{$soh}.com/>"],
            'control character in the path' => ["<https://e.com/a{$soh}b>"],
            'control character before the closing bracket' => ["<https://e.com/{$soh}>"],
        ];
    }

    #[DataProvider('positionProvider')]
    public function testTheExclusionHoldsAtEveryPosition(string $source): void
    {
        $this->assertStringNotContainsString('<a ', $this->converter->convert($source . "\n"));
    }

    /**
     * `link_destination` IS A DIFFERENT PRODUCTION and is unchanged.
     *
     * A format character in an INLINE destination or a reference definition is
     * still an ordinary destination character. The two productions exclude
     * different characters - notably the backslash - and this clause moves only
     * `url_char`, so a fix applied to the wrong one shows up here.
     *
     * CONTROL characters are absent from these rows, and not because the clause
     * excludes them: this engine rewrites a U+0001 in an inline destination to a
     * LINE FEED, and a U+E000 to a NO-BREAK SPACE, both before any of this runs.
     * That is a pre-existing defect in the destination path with its own
     * question to answer, and pinning it here would pin it as correct.
     *
     * @return array<string, array{string, string}>
     */
    public static function destinationProvider(): array
    {
        return [
            'an inline destination' => ["[t](https://e\u{FEFF}.com/)\n", "https://e\u{FEFF}.com/"],
            'a reference definition' => ["[r]: https://e\u{FEFF}.com/\n\n[t][r]\n", "https://e\u{FEFF}.com/"],
            'a zero-width space in an inline destination'
                => ["[t](https://e\u{200B}.com/)\n", "https://e\u{200B}.com/"],
        ];
    }

    #[DataProvider('destinationProvider')]
    public function testAnInlineDestinationIsUnchanged(string $source, string $href): void
    {
        $html = $this->converter->convert($source);

        $this->assertStringContainsString('<a ', $html);
        $this->assertSame($href, self::hrefOf($html));
    }

    /**
     * `scheme` IS UNCHANGED AND IS ASCII. Only the BODY admits non-ASCII.
     */
    public function testANonAsciiSchemeIsNotAnAutolink(): void
    {
        $this->assertStringNotContainsString(
            '<a ',
            $this->converter->convert("<\u{4F8B}://example.com/>\n"),
        );
    }

    /**
     * THE NINE ASCII EXCLUSIONS DO NOT MOVE. The rule is spelled
     * `unicode_url_char - format_char - control_char` rather than "any
     * non-whitespace, non-control character" precisely so these stay out - the
     * looser spelling would re-admit them and move every implementation on a
     * question nobody asked.
     *
     * @return array<string, array{string}>
     */
    public static function asciiExclusionProvider(): array
    {
        return [
            'double quote' => ['"'],
            'backslash' => ['\\'],
            'backtick' => ['`'],
            'left brace' => ['{'],
            'right brace' => ['}'],
            'pipe' => ['|'],
            'caret' => ['^'],
        ];
    }

    #[DataProvider('asciiExclusionProvider')]
    public function testAnAsciiExclusionIsStillNotAUrlChar(string $character): void
    {
        $this->assertStringNotContainsString(
            '<a ',
            $this->converter->convert("<https://example.com/{$character}q>\n"),
        );
    }

    /**
     * The ASCII enumeration itself, which is what the control half is layered
     * on top of. Every character the grammar lists, in one body.
     */
    public function testTheWholeAsciiEnumerationIsStillAUrlChar(): void
    {
        $body = "https://example.com/AZaz09-._~:/?#[]@!$&'()*+,;=%";
        $html = $this->converter->convert('<' . $body . ">\n");

        $this->assertStringContainsString('<a ', $html);
        $this->assertSame($body, self::hrefOf($html));
    }

    /**
     * The `href` an anchor carries, decoded - so the comparison is against the
     * DESTINATION rather than against one of the several ways an engine may
     * spell an apostrophe on the way out.
     */
    private static function hrefOf(string $html): string
    {
        if (preg_match('/href="([^"]*)"/', $html, $m) !== 1) {
            return '';
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The closer scan carries the same judgment, because it calls the same
     * recognizer. A body this rule rejects is not an autolink there either, so
     * the `*` inside it closes the emphasis.
     */
    public function testTheCloserScanAgreesWithTheParser(): void
    {
        $bom = "\u{FEFF}";

        $this->assertStringNotContainsString(
            '<a ',
            $this->converter->convert("*a <http://e{$bom}.com/*> b*\n"),
        );
    }
}
