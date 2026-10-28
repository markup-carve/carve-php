<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class LoosePostblankListParagraphTest extends TestCase
{
    public function testBlankAfterUnmatchedBacktickLoosensItem(): void
    {
        $source = "- h\n  ```\n\n   a\n";
        $expected = "<ul>\n  <li><p>h\n<code></code></p>\n    <p>a</p>\n  </li>\n</ul>";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testNestedItemBlankLoosensOnlyItsOwnList(): void
    {
        $source = "- parent\n  - h\n    ```\n\n     a\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<p>h', $html);
        self::assertStringContainsString('<p>a</p>', $html);
    }

    public function testDeeperParagraphAfterBlankLoosensItem(): void
    {
        $source = "- h\n  ```\n\n    a\n";
        $expected = "<ul>\n  <li><p>h\n<code></code></p>\n    <p>a</p>\n  </li>\n</ul>";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
