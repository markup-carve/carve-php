<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ABlankAfterInlineCodeInAnItemLoosensListTest extends TestCase
{
    public function testBlankBeforeSecondParagraphLoosensList(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li><p>a</p>\n    <blockquote><p>q\n<code>js</code></p></blockquote>\n    <p>c\n<code></code></p>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- a\n  > q\n   ```js\n\n   c\n   ```\n"),
        );
    }

    public function testSameBlocksWithoutBlankStayTight(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>a\n    <blockquote><p>q\n<code>js\nc\n</code></p></blockquote>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- a\n  > q\n   ```js\n   c\n   ```\n"),
        );
    }
}
