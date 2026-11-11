<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 S2 and PART 9 §24 C5: every frame restarts tab stops after its prefix.
 */
final class PerFrameTabColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'a follower misses the inner item after the outer frame strips its columns' => [
                "1. a\n   \t- a\n\n\t  \tb\n",
                "<ol>\n  <li><p>a</p>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>b</p>\n  </li>\n</ol>",
            ],
            'a tabbed code payload loses the structural indent of both items' => [
                "- a\n  - a\n   \t```\n   \tk\n   \t```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <pre><code>k\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a tab inside code content stays literal after frame stripping' => [
                "- a\n  - a\n   \t```\n    \t z\n   \t```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <pre><code>\t z\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a second tab beyond the code opener remains literal' => [
                "- a\n  - a\n\t```\n\t\t z\n\t```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <pre><code>\t z\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTabColumnsRestartInEveryReachedFrame(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
