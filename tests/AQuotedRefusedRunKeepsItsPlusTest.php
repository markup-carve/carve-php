<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5790: R03 protects a lone plus through the host's quote prefixes.
 */
final class AQuotedRefusedRunKeepsItsPlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item' => [
            "> - {.x\n> }\n> +\n> z\n",
            "<blockquote>\n  <ul>\n    <li>{.x\n}\n+\nz</li>\n  </ul>\n</blockquote>",
        ];

        yield 'description' => [
            "> :: t\n> : {.x\n> }\n> +\n> z\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n}\n+\nz</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'note' => [
            "> [^1]: {.x\n> }\n> +\n> z\n\nx[^1]\n",
            "<blockquote>\n\n</blockquote>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n}\n+\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'comment run with lazy tail' => [
            "> - {% a\n>  %}\n> +\nz\n",
            "<blockquote>\n  <ul>\n    <li>{% a\n%}</li>\n  </ul>\n</blockquote>\n<p>z</p>",
        ];

        yield 'attribute run with lazy closer' => [
            "> - {.x\n}\n> +\nz\n",
            "<blockquote>\n  <ul>\n    <li>{.x\n}\n+\nz</li>\n  </ul>\n</blockquote>",
        ];

        yield 'valid comment run leaves the next plus available' => [
            "- {% c\n   d %}\n+\nBody.\n",
            "<ul>\n  <li>Body.</li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testThePlusStaysInTheRunParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
