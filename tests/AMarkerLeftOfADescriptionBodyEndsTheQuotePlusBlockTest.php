<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkerLeftOfADescriptionBodyEndsTheQuotePlusBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'column 0' => [
                "> a\n+\n:: term\n: d\n- y\n",
                "<blockquote>\n  <p>a</p>\n  <dl>\n    <dt>term</dt>\n    <dd>d</dd>\n  </dl>\n</blockquote>\n<ul>\n  <li>y</li>\n</ul>",
            ],
            'after a lazy line, nested quote' => [
                "> > a\n+\n:: t\n: d\nlazy\n1. y\n",
                "<blockquote>\n  <blockquote><p>a</p></blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>d\nlazy</dd>\n  </dl>\n</blockquote>\n<ol>\n  <li>y</li>\n</ol>",
            ],
            'left of a wide body column' => [
                "> a\n+\n:: t\n:   d\n  - y\n",
                "<blockquote>\n  <p>a</p>\n  <dl>\n    <dt>t</dt>\n    <dd>d</dd>\n  </dl>\n</blockquote>\n<ul>\n  <li>y</li>\n</ul>",
            ],
            'at the body column stays in the description' => [
                "> a\n+\n:: t\n: d\n  - y\n",
                "<blockquote>\n  <p>a</p>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>d</p>\n      <ul>\n        <li>y</li>\n      </ul>\n    </dd>\n  </dl>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
