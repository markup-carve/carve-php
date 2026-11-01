<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AttachedFenceCloserColumnTest extends TestCase
{
    public function testACloserBelowTheAttachedFenceOpenerRemainsOutsideItsItem(): void
    {
        $source = "- foo\n  - +\n  ```\n  x\n ```\n";
        $expected = "<ul>\n  <li>foo\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code></code></pre>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
