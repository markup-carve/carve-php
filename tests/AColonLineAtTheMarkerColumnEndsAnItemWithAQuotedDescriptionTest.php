<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5346: a `: ` line at an item's marker column ends the item while a description inside a quote in it is open.
 */
final class AColonLineAtTheMarkerColumnEndsAnItemWithAQuotedDescriptionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a quote on the item marker line' => [
                "- > +\n  :: t\n  : d\n: d\n",
                "<ul>\n  <li>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>d</dd>\n      </dl>\n    </blockquote>\n  </li>\n</ul>\n<p>: d</p>",
            ],
            'a quote on its own line in the item' => [
                "- a\n  > +\n  :: t\n  : d\n: d\n",
                "<ul>\n  <li>a\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>d</dd>\n      </dl>\n    </blockquote>\n  </li>\n</ul>\n<p>: d</p>",
            ],
            'a term with no description keeps folding into the quote paragraph' => [
                "- a\n  :: t\n  > prose\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <blockquote><p>prose\n: z</p></blockquote>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testItem(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
