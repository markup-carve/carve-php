<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Successive markers offer one block (PART 9 §17 L3, CARVE-P9-067): the block after the run joins the host,
 * however deep the host sits (carve-php#4646).
 */
final class SuccessiveMarkersHandTheBlockToADeepHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            'nested quote' => ["> > q\n> +\n> +\n> tail\n", "<blockquote>\n  <blockquote>\n    <p>q</p>\n    <p>tail</p>\n  </blockquote>\n</blockquote>"],
            'three markers' => ["> > q\n> +\n> +\n> +\n> tail\n", "<blockquote>\n  <blockquote>\n    <p>q</p>\n    <p>tail</p>\n  </blockquote>\n</blockquote>"],
            'host two levels up' => ["> > > q\n> +\n> +\n> tail\n", "<blockquote>\n  <blockquote>\n    <blockquote><p>q</p></blockquote>\n    <p>tail</p>\n  </blockquote>\n</blockquote>"],
            'attached list' => ["> > q\n> +\n> +\n> - a\n> - b\n", "<blockquote>\n  <blockquote>\n    <p>q</p>\n    <ul>\n      <li>a</li>\n      <li>b</li>\n    </ul>\n  </blockquote>\n</blockquote>"],
            'attribute between the markers' => ["> > q\n> +\n> {.c}\n> +\n> tail\n", "<blockquote>\n  <blockquote>\n    <p>q</p>\n    <p class=\"c\">tail</p>\n  </blockquote>\n</blockquote>"],
            'quote in an item, attached list' => ["- > q\n  +\n  +\n  - a\n  - b\n", "<ul>\n  <li>\n    <blockquote>\n      <p>q</p>\n      <ul>\n        <li>a</li>\n        <li>b</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>"],
            'quote in an item, paragraph' => ["- > q\n  +\n  +\n  tail\n", "<ul>\n  <li>\n    <blockquote>\n      <p>q</p>\n      <p>tail</p>\n    </blockquote>\n  </li>\n</ul>"],
        ];
    }

    #[DataProvider('runs')]
    public function testTheBlockAfterTheRunJoinsTheHost(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
