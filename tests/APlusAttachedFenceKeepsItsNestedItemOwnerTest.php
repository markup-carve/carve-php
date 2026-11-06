<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class APlusAttachedFenceKeepsItsNestedItemOwnerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'heading after a blank' => [
                "- a\n  - +\n  ~~~\n\n    # h\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code></code></pre>\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'quote after a blank in an ordered item' => [
                "1. a\n   - +\n   ~~~\n\n     > q\n",
                "<ol>\n  <li>a\n    <ul>\n      <li>\n        <pre><code></code></pre>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'payload at the marker column before the blank' => [
                "- a\n  - +\n  ~~~\n  x\n\n    # h\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testAttachedFenceKeepsItsItem(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
