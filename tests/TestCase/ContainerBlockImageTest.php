<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A standalone image renders as a block-level <img>. Inside a blockquote or
 * list item it therefore takes the expanded (own-line, indented) layout, like
 * a heading or a div-nested image, NOT the single-paragraph compact form -
 * matching carve-js / carve-rs. Regression for the image-paragraph being
 * treated as a compact paragraph.
 */
class ContainerBlockImageTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBlockquoteImageIsExpanded(): void
    {
        $this->assertSame(
            "<blockquote>\n  <img src=\"/u\" alt=\"a\">\n</blockquote>\n",
            $this->converter->convert('> ![a](/u)'),
        );
    }

    public function testBlockquoteReferenceImageIsExpanded(): void
    {
        $this->assertSame(
            "<blockquote>\n  <img src=\"/u\" alt=\"a\">\n</blockquote>\n",
            $this->converter->convert("> ![a][r]\n\n[r]: /u"),
        );
    }

    public function testListItemImageIsExpanded(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <img src=\"/u\" alt=\"a\">\n  </li>\n</ul>\n",
            $this->converter->convert('- ![a](/u)'),
        );
    }

    public function testListItemImageThenParagraphKeepsIndent(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <img src=\"/u\" alt=\"a\">\n    <p>more</p>\n  </li>\n</ul>\n",
            $this->converter->convert("- ![a](/u)\n\n  more"),
        );
    }

    public function testBlockquoteTextParagraphStaysCompact(): void
    {
        // A real (non-image) single paragraph keeps the compact one-line form.
        $this->assertSame(
            "<blockquote><p>text</p></blockquote>\n",
            $this->converter->convert('> text'),
        );
    }
}
