<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\FenceAndContinuationLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContinuationMarkerRuling3482Test extends TestCase
{
    public static function ruledCases(): iterable
    {
        yield 'refused marker after a fence closer stays in the item' => [
            "- a\n\n  ```\n+\n+\n\ntail\n",
            "<ul>\n  <li>a\n    <pre><code></code></pre>\n    +\n  </li>\n</ul>\n<p>tail</p>",
        ];

        yield 'marker immediately after quote prefix' => [
            "> - a\n>+\n> tail\n",
            "<blockquote>\n  <ul>\n    <li>a\n      tail\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'nested marker ends item fence and retains its blank payload' => [
            "- a\n  - ```\n    x\n\n  +\n  > q\n",
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'tilde fence has the same nested marker owner' => [
            "- a\n  - ~~~\n    x\n\n  +\n  > q\n",
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'ordinary double marker keeps second outside the item' => [
            "- a\n+\n+\n\ntail\n",
            "<ul>\n  <li>a</li>\n</ul>\n<p>+</p>\n<p>tail</p>",
        ];
    }

    #[DataProvider('ruledCases')]
    public function testRuledHtml(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testRefusedSecondMarkerReportsItsPhysicalColumn(): void
    {
        $warnings = (new FenceAndContinuationLinter())->lint("- a\n\n  ```\n+\n+\n\ntail\n");
        $refusals = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'continuation-marker-refused'));
        self::assertCount(1, $refusals);
        self::assertSame([5, 1], [$refusals[0]->line, $refusals[0]->column]);
    }
}
