<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A fenced code block opened on a list item's MARKER LINE (`- ``` `) keeps its
 * interior blank lines instead of truncating at the first one. Previously the
 * item-content collection treated any blank as a terminator even while the
 * marker-line fence was still open, so `a` closed the block and the remaining
 * `b` plus the closing fence leaked out as a stray paragraph with an empty
 * inline code span (carve-php#404). Matches carve-js / carve-rs.
 */
class MarkerLineFenceInteriorBlankTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testInteriorBlankIsFenceContent(): void
    {
        $html = $this->converter->convert("- ```\n  a\n\n  b\n  ```");
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n</ul>\n",
            $html,
        );
    }

    public function testMultipleInteriorBlanks(): void
    {
        $html = $this->converter->convert("- ```\n  a\n\n\n  b\n  ```");
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>a\n\n\nb\n</code></pre>\n  </li>\n</ul>\n",
            $html,
        );
    }

    public function testNoStrayInlineCodeSpanLeaks(): void
    {
        $html = $this->converter->convert("- ```\n  a\n\n  b\n  ```");
        $this->assertStringNotContainsString('<code></code>', $html);
    }

    public function testSiblingAfterInteriorBlankFenceStaysTight(): void
    {
        // No blank line separates the two items (the blank is inside the fence),
        // so the list is tight: the sibling text is not wrapped in <p>.
        $html = $this->converter->convert("- ```\n  a\n\n  b\n  ```\n- c");
        $this->assertStringContainsString('<li>c</li>', $html);
    }
}
