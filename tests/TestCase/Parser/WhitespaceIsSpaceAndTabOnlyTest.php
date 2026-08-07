<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 1: `whitespace = ' ' | '\t'`. Every other invisible character is
 * CONTENT - it is not dropped from the end of a content line, and a construct
 * whose content is one of them is not empty.
 *
 * Two characters can see the difference between that definition and the two
 * wider ones this engine used to spell it with:
 *
 * - VERTICAL TAB (U+000B) is in PHP's default `rtrim` charlist
 *   (`" \t\n\r\0\x0B"`) and PCRE reads it as `\s`.
 * - FORM FEED (U+000C) is NOT in the default charlist but PCRE still reads it
 *   as `\s`.
 *
 * So the heading's trailing trim and the heading's / caption's emptiness gates
 * each answered the same question differently, and only these two characters
 * can tell. Corpus `268-trailing-whitespace-on-a-content-line-is-dropped-7`
 * already pins the FORM FEED half for a PARAGRAPH line; there is no corpus row
 * for a vertical tab yet (markup-carve/carve-php#1038).
 *
 * Measured against carve-rs 0.1.1, which reads every case here the same way.
 * Explicit ids keep these assertions off the separate id-derivation clause.
 */
class WhitespaceIsSpaceAndTabOnlyTest extends TestCase
{
    protected const VT = "\x0B";

    protected const FF = "\x0C";

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testParagraphKeepsATrailingVerticalTab(): void
    {
        // CONTROL: the reference behavior every other content line has to
        // match. The paragraph collector already spells `whitespace` as
        // `" \t"`.
        $this->assertSame(
            '<p>abc' . self::VT . "</p>\n",
            $this->converter->convert('abc' . self::VT),
        );
    }

    public function testHeadingKeepsATrailingVerticalTab(): void
    {
        $this->assertSame(
            "<section id=\"h\">\n  <h1>a" . self::VT . "</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# a" . self::VT),
        );
    }

    public function testHeadingKeepsATrailingFormFeed(): void
    {
        // CONTROL for the charlist: a form feed is outside PHP's default
        // charlist, so it survived even before the fix. It fails the moment
        // the charlist is widened again.
        $this->assertSame(
            "<section id=\"h\">\n  <h1>a" . self::FF . "</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# a" . self::FF),
        );
    }

    public function testHeadingStillDropsTrailingSpacesAndTabs(): void
    {
        // CONTROL for the other direction: narrowing the charlist must not
        // become dropping nothing.
        $this->assertSame(
            "<section id=\"h\">\n  <h1>a</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# a \t"),
        );
    }

    public function testHeadingContentMayBeOneVerticalTab(): void
    {
        $this->assertSame(
            "<section id=\"h\">\n  <h1>" . self::VT . "</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# " . self::VT),
        );
    }

    public function testHeadingContentMayBeOneFormFeed(): void
    {
        $this->assertSame(
            "<section id=\"h\">\n  <h1>" . self::FF . "</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# " . self::FF),
        );
    }

    public function testBareHashIsStillNotAHeading(): void
    {
        // CONTROL: the gate still requires content. `# ` holds nothing but
        // `whitespace`.
        $this->assertSame("<p>#</p>\n", $this->converter->convert("# \n"));
        $this->assertSame("<p>#</p>\n", $this->converter->convert("# \t\n"));
    }

    public function testAMultibyteHeadingIsStillAHeading(): void
    {
        // CONTROL for the `/u` trap: the gate is a BYTE-level class, and the
        // leading byte of a multi-byte character is not a space or a tab.
        // Adding `/u` here would make preg_match() fail outright on invalid
        // UTF-8 input instead.
        $this->assertSame(
            "<section id=\"h\">\n  <h1>\u{00E9}</h1>\n</section>\n",
            $this->converter->convert("{#h}\n# \u{00E9}"),
        );
    }

    public function testAVerticalTabHeadingInterruptsAParagraph(): void
    {
        // The recognizer that answers "does a heading start here" is a
        // separate copy of the gate from the one that parses it. Both have to
        // read the vertical tab as content or the paragraph swallows the line.
        $html = $this->converter->convert("p\n# " . self::VT);

        $this->assertStringStartsWith("<p>p</p>\n", $html);
        $this->assertStringContainsString('<h1>' . self::VT . '</h1>', $html);
    }

    public function testAVerticalTabHeadingIsAHeadingInsideADiv(): void
    {
        $html = $this->converter->convert("::: note\np\n# " . self::VT . "\n:::");

        $this->assertStringContainsString('<p>p</p>', $html);
        $this->assertStringContainsString(self::VT . '</h1>', $html);
    }

    public function testAVerticalTabHeadingIsAHeadingInsideABlockQuote(): void
    {
        $html = $this->converter->convert("> p\n> # " . self::VT);

        $this->assertStringContainsString('<p>p</p>', $html);
        $this->assertStringContainsString(self::VT . '</h1>', $html);
    }

    public function testTableCaptionContentMayBeOneVerticalTab(): void
    {
        $this->assertSame(
            "<table>\n  <caption>" . self::VT . "</caption>\n  <tbody>\n"
                . "    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            $this->converter->convert("| a |\n^ " . self::VT),
        );
    }

    public function testFigureCaptionContentMayBeOneFormFeed(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>" . self::FF
                . "</figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^ " . self::FF),
        );
    }

    public function testBareCaretIsStillNotACaption(): void
    {
        // CONTROL: the caption gate still requires content.
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n^</p>\n",
            $this->converter->convert("![a](/u)\n^ \n"),
        );
    }

    public function testCaptionKeepsATrailingVerticalTab(): void
    {
        // CONTROL: pins the charlist markup-carve/carve-php#1037 chose for the
        // caption, which this change has to leave alone.
        $this->assertSame(
            "<table>\n  <caption>Cap" . self::VT . "</caption>\n  <tbody>\n"
                . "    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            $this->converter->convert("| a |\n^ Cap" . self::VT),
        );
    }

    public function testCaptionKeepsATrailingFormFeed(): void
    {
        $this->assertSame(
            "<table>\n  <caption>Cap" . self::FF . "</caption>\n  <tbody>\n"
                . "    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            $this->converter->convert("| a |\n^ Cap" . self::FF),
        );
    }
}
