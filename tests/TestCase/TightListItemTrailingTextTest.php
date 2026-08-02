<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for trailing text after a closed block inside a list item.
 *
 * In a TIGHT list item, text that follows a closed block (a fenced code block,
 * a `:::` div, or an admonition) is part of the item's inline content and must
 * render BARE, matching the item's tightness. carve-js and the executable spec
 * oracle render it bare (corpus category 162); carve-php previously wrapped it
 * in a spurious <p>. A LOOSE item keeps every <p>.
 */
class TightListItemTrailingTextTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTightTrailingTextAfterFenceIsBare(): void
    {
        $input = "- item\n  ```\n  c\n  ```\n  tail\n";

        $expected = "<ul>\n"
            . "  <li>item\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    tail\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testTightTrailingTextAfterAdmonitionIsBare(): void
    {
        $input = "- item\n  ::: note\n  body\n  :::\n  tail\n";

        $expected = "<ul>\n"
            . "  <li>item\n"
            . "    <aside class=\"admonition note\">\n"
            . "      <p>body</p>\n"
            . "    </aside>\n"
            . "    tail\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testTightTrailingTextInOrderedListIsBare(): void
    {
        $input = "1. item\n   ```\n   c\n   ```\n   tail\n";

        $expected = "<ol>\n"
            . "  <li>item\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    tail\n"
            . "  </li>\n"
            . "</ol>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testTightMultiLineTrailingTextIsBare(): void
    {
        $input = "- item\n  ```\n  c\n  ```\n  t1\n  t2\n";

        // The soft break between t1 and t2 stays flush (inline continuation),
        // not indented and not wrapped.
        $expected = "<ul>\n"
            . "  <li>item\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    t1\n"
            . "t2\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testTightTrailingTextAfterLeadingFenceIsBare(): void
    {
        $input = "- ```\n  c\n  ```\n  tail\n";

        $expected = "<ul>\n"
            . "  <li>\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    tail\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testTightTrailingParagraphBetweenBlocksIsBare(): void
    {
        $input = "- item\n  ```\n  c\n  ```\n  mid\n  > q\n";

        $expected = "<ul>\n"
            . "  <li>item\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    mid\n"
            . "    <blockquote><p>q</p></blockquote>\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }

    /**
     * A LOOSE item (blank line separating content) DOES wrap the trailing text
     * in a <p>. Only tight items render it bare.
     */
    public function testLooseTrailingTextStillWraps(): void
    {
        $input = "- item\n\n  ```\n  c\n  ```\n\n  tail\n";

        $expected = "<ul>\n"
            . "  <li><p>item</p>\n"
            . "    <pre><code>c\n"
            . "</code></pre>\n"
            . "    <p>tail</p>\n"
            . "  </li>\n"
            . "</ul>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }
}
