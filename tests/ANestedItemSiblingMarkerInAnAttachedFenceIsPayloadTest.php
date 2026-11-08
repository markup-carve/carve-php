<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sibling marker inside a nested item's unclosed attached fence is payload, blank ahead or not (carve#5499).
 */
class ANestedItemSiblingMarkerInAnAttachedFenceIsPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $inner = static fn (string $code, string $after = ''): string => "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>"
            . $code . "</code></pre>\n" . $after . "      </li>\n    </ul>\n  </li>\n</ul>";

        return [
            'ruling reproduction, later blank' => [
                "- - +\n  ```\n  - z\n\nq\n",
                $inner("- z\n") . "\n<p>q</p>",
            ],
            'no later blank' => [
                "- - +\n  ```\n  - z\n",
                $inner("- z\n"),
            ],
            'another bullet kind' => [
                "- - +\n  ```\n  * z\n\nq\n",
                $inner("* z\n") . "\n<p>q</p>",
            ],
            'an ordered marker' => [
                "- - +\n  ```\n  1. z\n\nq\n",
                $inner("1. z\n") . "\n<p>q</p>",
            ],
            'a line after the marker' => [
                "- - +\n  ```\n  - z\n  q\n\nr\n",
                $inner("- z\nq\n") . "\n<p>r</p>",
            ],
            'a line at the item column after the blank' => [
                "- - +\n  ```\n  2. z\n\n    q\n",
                $inner("2. z\n", "        <p>q</p>\n"),
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerIsFenceContent(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testACloserPastTheBlankKeepsAMarkerPlusAsPayload(): void
    {
        self::assertSame(
            "<ol start=\"2\" data-delim=\")\">\n  <li>\n    <pre><code>- +\n\n</code></pre>\n  </li>\n</ol>",
            CarveConverter::create()->convert("2) +\n```\n- +\n\n```\n"),
        );
    }

    /**
     * The carve#5509 shape at the top level: the marker is payload there too (carve#5531).
     */
    public function testCarve5509TopLevelMarkerIsPayload(): void
    {
        self::assertSame(
            "<ol>\n  <li>\n    <pre><code>- z\n</code></pre>\n  </li>\n</ol>\n<p>y</p>",
            CarveConverter::create()->convert("1. +\n```\n- z\n\n  y\n"),
        );
    }
}
