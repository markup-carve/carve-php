<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ABlankAfterInlineCodeInAnItemLoosensListTest extends TestCase
{
    use RuleATodo;

    public function testBlankBeforeSecondParagraphLoosensList(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    <pre><code class=\"language-js\">\nc\n</code></pre>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- a\n  > q\n   ```js\n\n   c\n   ```\n"),
        );
    }

    public function testSameBlocksWithoutBlankStayTight(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    <pre><code class=\"language-js\">c\n</code></pre>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- a\n  > q\n   ```js\n   c\n   ```\n"),
        );
    }
}
