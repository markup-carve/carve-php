<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sibling marker inside a top-level item's unclosed attached fence is payload, blank ahead or not (carve#5531).
 */
class ATopLevelSiblingMarkerInAnAttachedFenceIsPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $item = static fn (string $list, string $code): string => '<' . $list . ">\n  <li>\n    <pre><code>" . $code
            . "</code></pre>\n  </li>\n</" . $list . '>';

        return [
            'ruling reproduction' => [
                "- +\n```\n- z\n\nq\n",
                $item('ul', "- z\n") . "\n<p>q</p>",
            ],
            'no later blank' => [
                "- +\n```\n- z\n",
                $item('ul', "- z\n"),
            ],
            'a line before the marker' => [
                "- +\n```\nx\n- z\n\nq\n",
                $item('ul', "x\n- z\n") . "\n<p>q</p>",
            ],
            'an ordered host and a bullet marker' => [
                "1. +\n~~~\n- z\n\nq\n",
                $item('ol', "- z\n") . "\n<p>q</p>",
            ],
            'a task marker' => [
                "- +\n```\n- [ ] z\n\nq\n",
                $item('ul', "- [ ] z\n") . "\n<p>q</p>",
            ],
            'a line at the item column after the blank' => [
                "- +\n```\n- z\n\n  q\n",
                "<ul>\n  <li>\n    <pre><code>- z\n</code></pre>\n    <p>q</p>\n  </li>\n</ul>",
            ],
            'a marker after the blank opens the next item' => [
                "- +\n```\n- z\n\n- q\n",
                "<ul>\n  <li>\n    <pre><code>- z\n</code></pre>\n  </li>\n  <li><p>q</p></li>\n</ul>",
            ],
            'a closer past the blank keeps the blank' => [
                "- +\n```\n- z\n\n```\n",
                $item('ul', "- z\n\n"),
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerIsFenceContent(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    #[DataProvider('cases')]
    public function testTheFormatterKeepsTheReading(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, CarveConverter::create()->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
