<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\TestCase;

class FoldedFenceListLoosenessTest extends TestCase
{
    public function testBlankSeparatedParagraphsAfterAFoldedFenceAreLoose(): void
    {
        self::assertSame(
            "<ul>\n  <li><p>h\n<code></code></p>\n    <p>b\n<code></code></p>\n  </li>\n</ul>\n",
            (new CarveConverter())->convert("- h\n  ```\n\n  b\n```\n"),
        );
        self::assertSame(
            "<ul>\n  <li><p>o\n<code>\nx</code></p>\n    <p>t</p>\n  </li>\n</ul>\n",
            (new CarveConverter())->convert("- o\n  ```\nx\n\n  t\n"),
        );
        foreach (['```', '~~~'] as $run) {
            $beforeCloser = "- h\n  {$run}\n\n  b\n{$run}\n";
            $noCloser = "- o\n  {$run}\nx\n\n  t\n";
            foreach ([$beforeCloser, $noCloser] as $source) {
                $html = (new CarveConverter())->convert($source);
                self::assertStringContainsString('<li><p>', $html);
                self::assertSame(2, substr_count($html, '<p>'));
                self::assertSame(2, substr_count($html, '</p>'));
            }
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
