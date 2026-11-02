<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected output is the spec model's at carve main 5a82756e (carve#4798, CARVE-P9-084).
 */
final class AnAttachedColonFrameStopsAtADescriptionSiblingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function frames(): iterable
    {
        $emptyThenSibling = "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n\n    </div>\n  </dd>\n  <dd>b</dd>\n</dl>\n<div>\n\n</div>";

        yield 'first-block plus, later closer' => [":: t\n: +\n:::\n: b\n:::\n", $emptyThenSibling];

        yield 'first-block plus, wider frame' => [":: t\n: +\n::::\n: b\n::::\n", $emptyThenSibling];

        yield 'own-line plus, later closer' => [
            ":: t\n: a\n+\n:::\n: b\n:::\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <div>\n\n    </div>\n  </dd>\n  <dd>b</dd>\n</dl>\n<div>\n\n</div>",
        ];

        yield 'a term ends the frame' => [
            ":: t\n: +\n:::\n:: u\n: v\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n\n    </div>\n  </dd>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>",
        ];

        yield 'a sibling-shaped line in a code fence stays payload' => [
            ":: t\n: +\n:::\n```\n: b\n```\n:::\n: c\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n      <pre><code>: b\n</code></pre>\n    </div>\n  </dd>\n  <dd>c</dd>\n</dl>",
        ];
    }

    #[DataProvider('frames')]
    public function testTheFrameEndsAtTheSibling(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
