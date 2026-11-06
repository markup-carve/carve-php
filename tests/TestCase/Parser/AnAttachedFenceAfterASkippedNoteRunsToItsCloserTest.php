<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5288: invisible lines between `+` and its block are skipped (PART 9 §17 L3),
 * so a fence after a note definition is still the attached block and runs to its closer.
 * carve#5291 rides along: a fence that misses the nested span owner ends the span (PART 0 S1).
 */
final class AnAttachedFenceAfterASkippedNoteRunsToItsCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $item = "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>";

        return [
            'ticket' => ["- +\n  [^n]: x\n```\n\n```\n", $item],
            'attribute then note' => [
                "- +\n  {x}\n  [^n]: x\n```\n\n```\n",
                "<ul>\n  <li>\n    <pre x=\"\"><code>\n</code></pre>\n  </li>\n</ul>",
            ],
            'note then reference' => ["- +\n  [^n]: x\n  [r]: /u\n```\n\n```\n", $item],
            'ordered host' => [
                ". +\n  [^n]: x\n~~~\n\n~~~\n",
                "<ol>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ol>",
            ],
            'carve#5291' => [
                ". a\n  . %%%\n\n  ```\n%%%\n",
                "<ol>\n  <li><p>a</p>\n    <ol>\n      <li></li>\n    </ol>\n    <pre><code></code></pre>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheFenceStaysTheAttachedBlock(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
