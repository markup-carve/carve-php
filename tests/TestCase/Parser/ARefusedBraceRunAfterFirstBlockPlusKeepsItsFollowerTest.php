<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedBraceRunAfterFirstBlockPlusKeepsItsFollowerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'heading in note paragraph' => [
            "[^a]: +\n{.x\n.y}\n# h\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n.y}\n# h<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'list marker in description paragraph' => [
            ":: t\n: +\n{.x\n.y}\n- li\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}\n- li</dd>\n</dl>",
        ];
    }

    #[DataProvider('cases')]
    public function testFollowerContinuesRefusedRunParagraph(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
