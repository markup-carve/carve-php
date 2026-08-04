<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §4: a container whose body renders nothing keeps a BLANK LINE where
 * the body would be, with one exception - a BARE `:::` div, no type word,
 * closes on the next line.
 *
 * All four shapes are pinned here because the whole defect is that the shape
 * varies by kind: this engine emitted the compact form for the word-class div
 * too, which is the one shape nothing in the corpus pinned (carve#570).
 */
class EmptyContainerBodyTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testABareDivIsCompact(): void
    {
        $this->assertSame("<div>\n</div>\n", $this->converter->convert(":::\n:::"));
    }

    public function testAWordClassDivKeepsTheBlankLine(): void
    {
        $this->assertSame("<div class=\"b\">\n\n</div>\n", $this->converter->convert("::: b\n:::"));
    }

    public function testAnAdmonitionKeepsTheBlankLine(): void
    {
        $html = $this->converter->convert("::: note\n:::");
        $this->assertSame("<aside class=\"admonition note\">\n\n</aside>\n", $html);
    }

    public function testAnEmptyBlockquoteKeepsTheBlankLine(): void
    {
        $this->assertSame("<blockquote>\n\n</blockquote>\n", $this->converter->convert('>'));
    }

    public function testTheSplitIsOnTheOpenerNotOnTheClass(): void
    {
        // A class from a preceding attribute line does not make the div typed,
        // so it stays compact - as in carve-js and carve-rs.
        $this->assertSame("<div class=\"b\">\n</div>\n", $this->converter->convert("{.b}\n:::\n:::"));
        $this->assertSame("<div id=\"i\">\n</div>\n", $this->converter->convert("{#i}\n:::\n:::"));
    }

    public function testABodyThatRendersNothingIsAnEmptyBody(): void
    {
        // A container holding only a comment takes the empty shape.
        $this->assertSame("<div class=\"b\">\n\n</div>\n", $this->converter->convert("::: b\n%%% \nc\n%%%\n:::"));
    }
}
