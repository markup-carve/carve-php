<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5755: a tab stop restarts at a frame's per-frame column, which a marker on an off-stop tab run moved right.
 */
final class ATabRunMeasuresFromAShiftedFrameColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'a paragraph after a blank' => [
                "1. a\n   \t- a\n\n\t  \tb\n",
                "<ol>\n  <li><p>a</p>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>b</p>\n  </li>\n</ol>",
            ],
            'a quote' => [
                "1. a\n   \t- a\n\t  \t> z\n",
                "<ol>\n  <li>a\n    <ul>\n      <li>a</li>\n    </ul>\n    <blockquote><p>z</p></blockquote>\n  </li>\n</ol>",
            ],
            'a sibling marker' => [
                "1. a\n   \t- a\n\t  \t- c\n",
                "<ol>\n  <li>a\n    <ul>\n      <li>a</li>\n      <li>c</li>\n    </ul>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineStaysInTheOuterItem(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }
}
