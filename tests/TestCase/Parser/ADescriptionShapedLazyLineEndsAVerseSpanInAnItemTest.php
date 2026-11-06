<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5308: a bare line block has no open term, so a `: d` lazy line after
 * its unclosed `%%%` span is verse payload (PART 9 §23), not a description.
 */
final class ADescriptionShapedLazyLineEndsAVerseSpanInAnItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $nested = "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <div class=\"line-block\">\n"
            . "          <p>&nbsp;&nbsp;v<br>\n%s</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>";

        return [
            'ticket' => ["- a\n  - b\n    ::: |\n      v\n      %%%\n: d\n", sprintf($nested, ': d')],
            'indented lazy line' => ["- a\n  - b\n    ::: |\n      v\n      %%%\n  : d\n", sprintf($nested, ': d')],
            'one level' => [
                "- b\n  ::: |\n    v\n    %%%\n: d\n",
                "<ul>\n  <li>b\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;v<br>\n: d</p>\n    </div>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheLazyLineJoinsTheStanza(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
