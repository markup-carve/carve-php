<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sibling marker ends a bounded unclosed attached fence regardless of its list kind (PART 9 §17 L3).
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
                "<ol>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ol>\n<ul>\n  <li>z</li>\n</ul>\n<p>y</p>",
            ],
            'bullet to ordered, code' => [
                "- +\n```\n1. z\n+\n\ny\n",
                "<ul>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<ol>\n  <li>z</li>\n</ol>\n<p>y</p>",
            ],
            'ordered to another bullet, tilde fence' => [
                "1. +\n~~~\n* z\n+\n\ny\n",
                "<ol>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ol>\n<ul>\n  <li>z</li>\n</ul>\n<p>y</p>",
            ],
            'bullet to ordered, raw fence' => [
                "* +\n```=html\n1. z\n+\n\ny\n",
                "<ul>\n  <li>\n    \n  </li>\n</ul>\n<ol>\n  <li>z</li>\n</ol>\n<p>y</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheSiblingEndsTheFence(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
