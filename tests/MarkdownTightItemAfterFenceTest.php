<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownTightItemAfterFenceTest extends TestCase
{
    public function testTextAfterFenceStaysInTightItem(): void
    {
        $source = "- a\n  ```\n  c\n  ```\n  b\n";
        $carve = (new MarkdownToCarve())->convert($source);

        self::assertSame($source, $carve);
        self::assertSame("<ul>\n  <li>a\n    <pre><code>c\n</code></pre>\n    b\n  </li>\n</ul>\n", CarveConverter::create()->convert($carve));
    }

    public function testLineLeavingItemRemainsOutsideIt(): void
    {
        $carve = (new MarkdownToCarve())->convert("- a\n  ```\n  c\n  ```\nout\n");

        self::assertSame("<ul>\n  <li>a\n    <pre><code>c\n</code></pre>\n  </li>\n</ul>\n<p>out</p>\n", CarveConverter::create()->convert($carve));
    }
}
