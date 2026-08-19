<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two divergences on a captioned host whose content spans a line boundary,
 * found while ruling markup-carve/carve#1352 (markup-carve/carve-php#1422).
 *
 * Both are about the FIGURE around the host rather than the host itself: the
 * same image and the same math WITHOUT a caption were always right, which is
 * what pointed at the figure step in each case.
 */
class ACaptionedHostDoesNotIndentInsideAValueTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A NEWLINE INSIDE AN ATTRIBUTE VALUE IS CONTENT, so the figure's own block
     * indentation must not reach it. Applied to the rendered block as a whole
     * rather than to its lines, two spaces landed inside `alt` and changed what
     * the attribute says. Pinned by corpus 351-5.
     */
    public function testTheFiguresIndentDoesNotReachInsideAnAttributeValue(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/i\" alt=\"a\nb\">\n  <figcaption>cap</figcaption>\n</figure>\n",
            $this->html("![a\nb](/i)\n^ cap\n"),
        );
    }

    /**
     * The CONTROL that points at the indentation step rather than the image:
     * the same image without a caption was always right.
     */
    public function testTheSameImageWithoutACaptionIsUnchanged(): void
    {
        $this->assertStringContainsString("alt=\"a\nb\"", $this->html("![a\nb](/i)\n"));
    }

    /**
     * And the figure still INDENTS the lines that are not inside a value - the
     * fix is a guard, not the removal of the indentation.
     *
     * @return array<string, array{string, string}>
     */
    public static function stillIndentedProvider(): array
    {
        return [
            'the caption line' => ["![a\nb](/i)\n^ cap\n", '  <figcaption>cap</figcaption>'],
            'the image line' => ["![a\nb](/i)\n^ cap\n", '  <img src="/i"'],
            // A value with no newline never reached the guard, and must still be
            // indented as one line.
            'an ordinary captioned image' => ["![a](/i)\n^ cap\n", '  <img src="/i" alt="a">'],
        ];
    }

    #[DataProvider('stillIndentedProvider')]
    public function testTheFigureStillIndentsItsOwnLines(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * A RAW `"` IN A CODE SPAN IS NOT AN ATTRIBUTE DELIMITER.
     *
     * The text and attribute paths escape that character; the verbatim one does
     * not. So the guard tracks whether the line ended inside a TAG rather than
     * counting quotes - a count reads a code span's own character as the start
     * of an attribute and stops indenting everything after it.
     */
    public function testACodeSpansOwnQuoteDoesNotStopTheIndentation(): void
    {
        $html = $this->html("> a `x \"y\" z` b\n>\n> c\n");

        $this->assertStringContainsString('<code>x "y" z</code>', $html);
        $this->assertStringContainsString('  <p>c</p>', $html);
    }

    /**
     * PART 9 section 4's second captionable host is a paragraph whose whole
     * content is a display-math span, READ ON ONE LINE. Spanning a line
     * boundary, carve-php alone built a figure where carve-js, carve-rs and the
     * executable spec leave a paragraph and the caption line literal.
     */
    public function testDisplayMathSpanningALineBoundaryIsNotCaptionable(): void
    {
        $this->assertSame(
            "<p><span class=\"math display\">\\[a\nb\\]</span>\n^ cap</p>\n",
            $this->html("$$`a\nb`\n^ cap\n"),
        );
    }

    /**
     * The CONTROL: on ONE line it still IS captionable. Without this the row
     * above passes on an engine that stopped captioning display math at all,
     * which is a different defect wearing the same output for one input.
     */
    public function testDisplayMathOnOneLineIsStillCaptionable(): void
    {
        $html = $this->html("$$`ab`\n^ cap\n");

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<figcaption>cap</figcaption>', $html);
    }

    /**
     * And INLINE math was never a captionable host - the rule is about the
     * DISPLAY spelling, not about math.
     */
    public function testInlineMathIsStillNotCaptionable(): void
    {
        $this->assertStringNotContainsString('<figure>', $this->html("$`ab`\n^ cap\n"));
    }
}
