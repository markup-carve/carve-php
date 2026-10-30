<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\TestCase;

class FoldedFenceListLoosenessTest extends TestCase
{
    /**
     * An unclosed run opens a fence in the item (carve#4032), so nothing folds and nothing loosens.
     */
    public function testAnUnclosedRunOpensAFenceInsteadOfFolding(): void
    {
        foreach (['```', '~~~'] as $run) {
            self::assertSame(
                "<ul>\n  <li>h\n    <pre><code>\nb\n</code></pre>\n  </li>\n</ul>\n<pre><code></code></pre>",
                (new CarveConverter())->convert("- h\n  {$run}\n\n  b\n{$run}\n"),
            );
            self::assertSame(
                "<ul>\n  <li>o\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>x</p>\n<p>t</p>",
                (new CarveConverter())->convert("- o\n  {$run}\nx\n\n  t\n"),
            );
        }
    }

    public function testPairedFencePayloadDoesNotLoosenItsItem(): void
    {
        $source = "- a\n  ```\n  x\n\n  y\n  ```\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<li>a', $html);
        self::assertStringContainsString("<pre><code>x\n\ny\n</code></pre>", $html);
        self::assertStringNotContainsString('<li><p>', $html);
    }

    public function testFoldedFenceWorkGrowsWithTheNumberOfItems(): void
    {
        $measure = static function (int $count): int {
            LayoutWork::reset();
            LayoutWork::$on = true;
            try {
                (new CarveConverter())->convert(str_repeat("- o\n  ```\nx\n\n  t\n", $count));
            } finally {
                LayoutWork::$on = false;
            }

            return LayoutWork::total();
        };

        $small = $measure(128);
        $large = $measure(256);
        self::assertGreaterThan(0, $small);
        self::assertLessThanOrEqual(3 * $small, $large);
    }
}
