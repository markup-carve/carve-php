<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5780: a refused run on the marker line that ends a sibling list keeps its follower and the lazy line.
 */
final class ARefusedRunAfterASiblingListKeepsItsFollowerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'sibling item' => [
            "- p\n  - q\n  - {% a\n    %}\n    # h\nplain\n",
            "<ul>\n  <li>p\n    <ul>\n      <li>q</li>\n      <li>{% a\n%}\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>plain</p>",
        ];

        yield 'sibling quote' => [
            "- p\n  > q\n  - {% a\n    %}\n    # h\nplain\n",
            "<ul>\n  <li>p\n    <blockquote><p>q</p></blockquote>\n    <ul>\n      <li>{% a\n%}\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>plain</p>",
        ];

        yield 'sibling list of another kind' => [
            "- p\n  1. q\n  - {% a\n  %}\n    # h\nplain\n",
            "<ul>\n  <li>p\n    <ol>\n      <li>q</li>\n    </ol>\n    <ul>\n      <li>{% a\n%}\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>plain</p>",
        ];

        $boundary = "- p\n  1. q\n  - {% a\n  %}\n    # h\nplain\n";
        $boundaryHtml = "<li><p>p</p>\n    <ol>\n      <li>q</li>\n    </ol>\n    <ul>\n      <li>{% a\n%}\n# h\nplain</li>\n    </ul>\n  </li>";

        yield 'two runs past boundaries' => [$boundary . "\n" . $boundary, "<ul>\n  " . $boundaryHtml . "\n  " . $boundaryHtml . "\n</ul>"];
        yield 'list marker follower stays text' => [
            "- p\n  1. q\n  - {.x\n  }\n    - r\nplain\n",
            "<ul>\n  <li>p\n    <ol>\n      <li>q</li>\n    </ol>\n    <ul>\n      <li>{.x\n}\n        <ul>\n          <li>r\nplain</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a quote the marker line closes still drops the run' => [
            ">  # h\n- {% a\n  - li\n> \n>  z %}\n",
            "<blockquote>\n  <h1 id=\"h\">h</h1>\n</blockquote>\n<ul>\n  <li>{% a\n    <ul>\n      <li>li</li>\n    </ul>\n  </li>\n</ul>\n<blockquote><p>z %}</p></blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheFollowerStaysInTheRunsParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
