<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note definition right after a refused brace run is that run's paragraph text, and a later lazy line continues it (CARVE-P2-035, PART 0 S4, carve#5784, carve#5769).
 */
final class ANoteFollowerOfARefusedRunStaysInTheItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'list item' => [
            "- {.x\n}\n[^m]: n\nz\n",
            "<ul>\n  <li>{.x\n}\n[^m]: n\nz</li>\n</ul>",
        ];

        yield 'ordered item with a comment run' => [
            "1. {% x\n%}\n[^m]: n\nz\n",
            "<ol>\n  <li>{% x\n%}\n[^m]: n\nz</li>\n</ol>",
        ];

        yield 'quoted item' => [
            "> - {.x\n> }\n> [^m]: n\n> z\n",
            "<blockquote>\n  <ul>\n    <li>{.x\n}\n[^m]: n\nz</li>\n  </ul>\n</blockquote>",
        ];

        yield 'description in an item' => [
            "- :: t\n  : {.x\n}\n[^m]: n\nz\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>{.x\n}\n[^m]: n\nz</dd>\n    </dl>\n  </li>\n</ul>",
        ];

        yield 'description with a closer short of its body column' => [
            ":: t\n: {.x\n }\n[^m]: n\nz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n}\n[^m]: n\nz</dd>\n</dl>",
        ];

        yield 'note' => [
            "[^q]: {.x\n}\n[^m]: n\nz\n\ny[^q]\n",
            "<p>y<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n}\n[^m]: n\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a refused comment run in a note keeps its later lazy line' => [
            "[^n]: p\n{% a\n%}\n  # q\nafter\n\nx[^n]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p\n{% a\n%}\n# q\nafter<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a second note definition still leaves the item' => [
            "- {.x\n}\n[^m]: n\n[^k]: k\n",
            "<ul>\n  <li>{.x\n}\n[^m]: n</li>\n</ul>",
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
