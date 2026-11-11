<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5186: the lookahead of a nested `+` skips a tab-led line by its per-frame column
 * (PART 9 §24 C5), and the line it stops at is checked against the marker column from the line start.
 */
final class ANestedPlusMeasuresATabLedFollowerPerFrameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'the reproduction: four spaces and a tab' => [
                "1. x\n   - y\n     1. +\n    \t{#i}\n     q\n",
                "<ol>\n  <li>x\n    <ul>\n      <li>y\n        <ol>\n          <li></li>\n        </ol>\n        <p id=\"i\">q</p>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'two tabs' => [
                "1. x\n   - y\n     1. +\n\t\t{#i}\n     q\n",
                "<ol>\n  <li>x\n    <ul>\n      <li>y\n        <ol>\n          <li></li>\n        </ol>\n        <p id=\"i\">q</p>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'a bullet under an ordered item' => [
                "1. x\n   1. y\n      - +\n    \t{#i}\n      q\n",
                "<ol>\n  <li>x\n    <ol>\n      <li>y\n        <ul>\n          <li></li>\n        </ul>\n        <p id=\"i\">q</p>\n      </li>\n    </ol>\n  </li>\n</ol>",
            ],
            'a tab past the content column attaches' => [
                "1. x\n   - y\n     1. +\n      \t{#i}\n     q\n",
                "<ol>\n  <li>x\n    <ul>\n      <li>y\n        <ol>\n          <li><p id=\"i\">q</p></li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'line-start stops at the marker column hold the block' => [
                "- x\n  - y\n    1. +\n  \t{#i}\n\n    q\n",
                "<ul>\n  <li>x\n    <ul>\n      <li><p>y</p>\n        <ol>\n          <li></li>\n        </ol>\n        <p>q</p>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a refused plus keeps a line at its per-frame content column' => [
                "- x\n  - y\n    - +\n  \t{#i}\n\n    q\n",
                "<ul>\n  <li>x\n    <ul>\n      <li><p>y</p>\n        <ul>\n          <li></li>\n        </ul>\n        <p>q</p>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFollowerIsMeasuredPerFrame(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }

    #[DataProvider('cases')]
    public function testFmtKeepsTheReading(string $source, string $html): void
    {
        $written = CarveConverter::toCarve($source);

        self::assertSame($written, CarveConverter::toCarve($written));
        self::assertSame($html, CarveConverter::create()->convert($written));
    }
}
