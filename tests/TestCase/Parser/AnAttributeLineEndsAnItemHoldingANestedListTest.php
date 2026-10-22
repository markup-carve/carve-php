<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A column-0 block attribute below an item whose last block is a nested list ends the list and reaches the next
 * block, as it does below an item without one (carve-php#3742).
 */
final class AnAttributeLineEndsAnItemHoldingANestedListTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        $list = "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n";

        return [
            'one-line, list follower' => ["- a\n  - b\n{.c}\n- d\n", $list . "<ul class=\"c\">\n  <li>d</li>\n</ul>"],
            'one-line, paragraph follower' => ["- a\n  - b\n{.c}\npara\n", $list . '<p class="c">para</p>'],
            'wrapped, list follower' => ["- a\n  - b\n{.c\n}\n- d\n", $list . "<ul class=\"c\">\n  <li>d</li>\n</ul>"],
            'one-line, fence follower' => ["- a\n  - b\n{.c}\n~~~\ny\n~~~\n", $list . "<pre class=\"c\"><code>y\n</code></pre>"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheAttributeReachesTheNextBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
