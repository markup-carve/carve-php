<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A glued item attribute does not move the item's content column, but a later
 * brace is tested at its written column (CARVE-P2-035, carve#5882).
 */
final class AQuotedMarkerGluedBraceUsesWrittenColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        $refused = "<blockquote>\n  <ul>\n    <li class=\"a\">{.x\n.y}\nplain</li>\n  </ul>\n</blockquote>";
        foreach ([5, 6, 7, 8] as $column) {
            yield "refused at continuation column $column" => [
                "> -{.a} {.x\n> " . str_repeat(' ', $column - 2) . ".y}\nplain\n",
                $refused,
            ];
        }

        yield 'ordered marker and id' => [
            "> 1.{#i} {.x\n>       .y}\nplain\n",
            "<blockquote>\n  <ol>\n    <li id=\"i\">{.x\n.y}\nplain</li>\n  </ol>\n</blockquote>",
        ];

        yield 'two quote levels' => [
            "> > -{.a} {.x\n> >      .y}\nplain\n",
            "<blockquote>\n  <blockquote>\n    <ul>\n      <li class=\"a\">{.x\n.y}\nplain</li>\n    </ul>\n  </blockquote>\n</blockquote>",
        ];

        yield 'past the written brace opens an attribute block' => [
            "> -{.a} {.x\n>         .y}\nplain\n",
            "<blockquote>\n  <ul>\n    <li class=\"a\"></li>\n  </ul>\n</blockquote>\n<p>plain</p>",
        ];
    }

    #[DataProvider('runs')]
    public function testWrittenBraceColumnKeepsRefusedParagraphsOpen(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
