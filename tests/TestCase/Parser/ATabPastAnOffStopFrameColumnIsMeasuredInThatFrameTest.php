<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24 C5 (carve#4085): tab stops restart at every frame column a leading run reaches.
 */
final class ATabPastAnOffStopFrameColumnIsMeasuredInThatFrameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'a rule past the nested marker' => [
                "1. * b\n   \t---\n",
                "<ol>\n  <li>\n    <ul>\n      <li>b\n        <hr>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'a quote past the nested marker' => [
                "1. * b\n   \t> q\n",
                "<ol>\n  <li>\n    <ul>\n      <li>b\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'a quote past a wide ordered marker' => [
                "* 10. b\n  \t> q\n",
                "<ul>\n  <li>\n    <ol start=\"10\">\n      <li>b\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ol>\n  </li>\n</ul>",
            ],
            'a marker below a tab-indented item in a description' => [
                ":: t\n:  a\n\n\t- x\n   \t- y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <ul>\n      <li>x\n        <ul>\n          <li>y</li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>",
            ],
            'a marker on the same off-stop run is a sibling' => [
                ":: t\n:  a\n\n   \t- x\n   \t- y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <ul>\n      <li>x</li>\n      <li>y</li>\n    </ul>\n  </dd>\n</dl>",
            ],
            'the space twin stays in the outer item' => [
                "1. * b\n    > q\n",
                "<ol>\n  <li>\n    <ul>\n      <li>b</li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTheRunIsMeasuredInEveryFrameItReaches(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
