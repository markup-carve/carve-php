<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An escaped space is written back as an ESCAPED SPACE.
 *
 * Resolving it to a real no-break space lost the distinction the parser draws:
 * `10\ kg` came back carrying U+00A0, which re-parses as a literal nbsp rather
 * than as an escape, so the text node differed even though the HTML did not.
 * carve-js fixed this in carve#369 and carve-rs in carve-rs#310; this engine still
 * carried the older behavior (carve#352, corpus 29-non-breaking-space).
 */
class EscapedSpaceTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnEscapedSpaceSurvivesAsAnEscape(): void
    {
        $this->assertSame("10\\ kg\n", CarveConverter::toCarve("10\\ kg\n"));
    }

    public function testItSurvivesBesideQuotes(): void
    {
        $source = "say\\ 'twas a fine\\ \"day\"\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * The backslash the placeholder expands to must not be escaped again. The
     * expansion happens after escaping for exactly that reason.
     */
    public function testTheExpandedBackslashIsNotDoubled(): void
    {
        $out = CarveConverter::toCarve("10\\ kg\n");

        $this->assertStringNotContainsString('\\\\', $out);
        $this->assertSame(
            $this->converter->convert("10\\ kg\n"),
            $this->converter->convert($out),
        );
        $this->assertSame($out, CarveConverter::toCarve($out), 'fmt is not idempotent');
    }

    /**
     * A line block's leading indentation still resolves to ORDINARY spaces: that
     * is the source form the parser reads back as indentation, whereas an escape
     * or a real nbsp re-parses as literal text (carve#359).
     */
    public function testALineBlockIndentIsStillPlainSpaces(): void
    {
        $source = "::: |\nRoses are red,\n  indented line.\n:::\n";
        $out = CarveConverter::toCarve($source);

        $this->assertStringNotContainsString('\\ ', $out);
        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert($out),
        );
    }

    /**
     * A literal non-breaking space the author typed is left alone -- it is content,
     * not an escape, and the two must not be conflated.
     */
    public function testALiteralNonBreakingSpaceIsUntouched(): void
    {
        $source = "10\u{00A0}kg\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
