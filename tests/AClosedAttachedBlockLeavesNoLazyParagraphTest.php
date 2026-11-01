<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AClosedAttachedBlockLeavesNoLazyParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function attachedBlocks(): iterable
    {
        yield 'nested item' => ["- d\n  - +\n  :::\nafter\n", "<ul>\n  <li>d\n    <ul>\n      <li>\n        <div>\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>after</p>"];
        yield 'marker line child' => ["- - +\n  :::\nafter\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <div>\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>after</p>"];
    }

    #[DataProvider('attachedBlocks')]
    public function testAFlushLeftLineLeavesTheEmptyDiv(string $source, string $expected): void
    {
        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
