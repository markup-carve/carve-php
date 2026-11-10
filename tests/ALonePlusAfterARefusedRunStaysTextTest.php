<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5086: a lone `+` at the host marker column right after a refused run is paragraph text (carve#5658 R03).
 */
final class ALonePlusAfterARefusedRunStaysTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $nested = "<ul>\n  <li>\n    <ul>\n      <li>{.x\n}\n+\nz</li>\n    </ul>\n  </li>\n</ul>";

        yield 'item, lazy closer' => ["- {.x\n}\n+\nz\n", "<ul>\n  <li>{.x\n}\n+\nz</li>\n</ul>"];
        yield 'ordered item' => ["1. {.x\n}\n+\nz\n", "<ol>\n  <li>{.x\n}\n+\nz</li>\n</ol>"];
        yield 'closer at the brace' => ["- {.x\n .y}\n+\nz\n", "<ul>\n  <li>{.x\n.y}\n+\nz</li>\n</ul>"];
        yield 'stacked host, its marker column' => ["- - {.x\n}\n  +\nz\n", $nested];
        yield 'stacked host, outer marker column' => ["- - {.x\n}\n+\nz\n", "<ul>\n  <li>\n    <ul>\n      <li>{.x\n}</li>\n    </ul>\n    z\n  </li>\n</ul>"];
        yield 'description' => [":: t\n: {.x\n}\n+\nz\n", "<dl>\n  <dt>t</dt>\n  <dd>{.x\n}\n+\nz</dd>\n</dl>"];
        yield 'note' => [
            "[^1]: {.x\n}\n+\nz\n\nx[^1]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n}\n+\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'attached run keeps its reading' => ["- +\n{.x\n.y}\n+\n  zq\n", "<ul>\n  <li>{.x\n.y}\n    zq\n  </li>\n</ul>"];
        yield 'off-column quote plus stays a marker' => [
            "> - +\n> {.x\n> .y}\n  > +\n>   zq\n",
            "<blockquote>\n  <ul>\n    <li>{.x\n.y}\n      zq\n    </li>\n  </ul>\n</blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testThePlusIsText(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
