<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sibling marker of any list kind is payload of an unclosed attached fence, blank ahead or not (carve#5531).
 */
class ADifferentListMarkerEndsAnAttachedFenceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'ordered to bullet, code' => [
                "1. +\n```\n- z\n+\n\ny\n",
                "<ol>\n  <li>\n    <pre><code>- z\n+\n</code></pre>\n  </li>\n</ol>\n<p>y</p>",
            ],
            'bullet to ordered, code' => [
                "- +\n```\n1. z\n+\n\ny\n",
                "<ul>\n  <li>\n    <pre><code>1. z\n+\n</code></pre>\n  </li>\n</ul>\n<p>y</p>",
            ],
            'ordered to another bullet, tilde fence' => [
                "1. +\n~~~\n* z\n+\n\ny\n",
                "<ol>\n  <li>\n    <pre><code>* z\n+\n</code></pre>\n  </li>\n</ol>\n<p>y</p>",
            ],
            'bullet to ordered, raw fence' => [
                "* +\n```=html\n1. z\n+\n\ny\n",
                "<ul>\n  <li>\n    1. z\n+\n  </li>\n</ul>\n<p>y</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheSiblingIsFenceContent(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
