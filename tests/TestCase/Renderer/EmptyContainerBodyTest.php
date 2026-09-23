<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §4: a container whose body renders nothing keeps a blank line where
 * the body would be.
 */
class EmptyContainerBodyTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testABareDivKeepsTheBlankLine(): void
    {
        $this->assertSame("<div>\n\n</div>\n", $this->converter->convert(":::\n:::"));
    }

    public function testAWordClassDivKeepsTheBlankLine(): void
    {
        $this->assertSame("<div class=\"b\">\n\n</div>\n", $this->converter->convert("::: b\n:::"));
    }

    public function testAnAdmonitionKeepsTheBlankLine(): void
    {
        $html = $this->converter->convert("::: note\n:::");
        $this->assertSame("<aside class=\"admonition note\" aria-label=\"Note\">\n\n</aside>\n", $html);
    }

    public function testAnEmptyBlockquoteKeepsTheBlankLine(): void
    {
        $this->assertSame("<blockquote>\n\n</blockquote>\n", $this->converter->convert('>'));
    }

    public function testAnEmptyLineBlockKeepsTheBlankLine(): void
    {
        $this->assertSame("<div class=\"line-block\">\n\n</div>\n", $this->converter->convert("::: |\n:::"));
    }

    public function testAnEmptyLocalHardBreakBlockKeepsTheBlankLine(): void
    {
        $this->assertSame("<div class=\"hardbreaks\">\n\n</div>\n", $this->converter->convert("::: \\\n:::"));
    }

    public function testAnEmptyFigureGroupKeepsTheBlankLine(): void
    {
        $this->assertSame(
            "<figure class=\"carve-figure-group\">\n\n</figure>\n",
            $this->converter->convert("::: figure\n:::"),
        );
    }

    public function testAnAttributedBareDivKeepsTheBlankLine(): void
    {
        $this->assertSame("<div class=\"b\">\n\n</div>\n", $this->converter->convert("{.b}\n:::\n:::"));
        $this->assertSame("<div id=\"i\">\n\n</div>\n", $this->converter->convert("{#i}\n:::\n:::"));
    }

    public function testABodyThatRendersNothingIsAnEmptyBody(): void
    {
        // A container holding only a comment takes the empty shape.
        $this->assertSame("<div class=\"b\">\n\n</div>\n", $this->converter->convert("::: b\n%%% \nc\n%%%\n:::"));
    }
}
