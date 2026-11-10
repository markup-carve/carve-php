<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The line a refused brace run's paragraph keeps is paragraph text, so a lazy line after it folds in (CARVE-P2-035, carve#5191).
 * Expected output is the spec model's at carve main 9bc03fe5.
 */
final class ARefusedBraceRunKeepsItsParagraphOpenForALazyLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'an item paragraph a plus pulled from column 0' => [
            "- a\n+\n{x\ny}\n  # c\nmore\n",
            "<ul>\n  <li>a\n    {x\ny}\n    <h1 id=\"c\">c</h1>\n  </li>\n</ul>\n<p>more</p>",
        ];

        yield 'an item lead paragraph' => [
            "- a\n  {x\n  y}\n  # c\nmore\n",
            "<ul>\n  <li>a\n{x\ny}\n    <h1 id=\"c\">c</h1>\n  </li>\n</ul>\n<p>more</p>",
        ];

        yield 'a quote' => [
            "> a\n> {x\n> y}\n> # c\nmore\n",
            "<blockquote><p>a\n{x\ny}</p><h1 id=\"c\">c</h1></blockquote><p>more</p>",
        ];

        yield 'a quote follower' => [
            "- a\n  {x\n  y}\n  > c\nmore\n",
            "<ul>\n  <li>a\n{x\ny}\n    <blockquote><p>c\nmore</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'a run on the marker line keeps a marker follower' => [
            "- {x\n  y}\n  - c\n",
            "<ul>\n  <li>{x\ny}\n    <ul>\n      <li>c</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a blank still ends the paragraph' => [
            "- a\n  {x\n  y}\n  # c\n\nmore\n",
            "<ul>\n  <li>a\n{x\ny}\n    <h1 id=\"c\">c</h1>\n  </li>\n</ul>\n<p>more</p>",
        ];
    }

    #[DataProvider('hosts')]
    public function testTheKeptLineLeavesTheParagraphOpen(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
