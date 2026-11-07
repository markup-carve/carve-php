<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5363: once a block follows a closed description, a `: ` line at the item's marker column folds into the item's paragraph.
 */
final class AColonLineAfterAClosedDescriptionJoinsTheItemParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a heading after the description' => [
                "- a\n  :: t\n  : u\n  # H\n  w\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n    <h1 id=\"H\">H</h1>\n    w\n: z\n  </li>\n</ul>",
            ],
            'a blank and a paragraph after the description' => [
                "- a\n  :: t\n  : u\n\n  w\n: z\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n    <p>w\n: z</p>\n  </li>\n</ul>",
            ],
            'a quote after the description' => [
                "- a\n  :: t\n  : u\n  > q\n\n  w\n: z\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n    <blockquote><p>q</p></blockquote>\n    <p>w\n: z</p>\n  </li>\n</ul>",
            ],
            'in an ordered item' => [
                "1. a\n   :: t\n   : u\n   ---\n   w\n: z\n",
                "<ol>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n    <hr>\n    w\n: z\n  </li>\n</ol>",
            ],
            'a term with no description still ends the item' => [
                "- a\n  :: t\n\n  # H\n  w\n: z\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <h1 id=\"H\">H</h1>\n    <p>w</p>\n  </li>\n</ul>\n<p>: z</p>",
            ],
            'an open description still ends the item' => [
                "- a\n  :: t\n  : u\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n  </li>\n</ul>\n<p>: z</p>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testLine(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
