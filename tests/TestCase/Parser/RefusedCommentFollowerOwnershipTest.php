<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefusedCommentFollowerOwnershipTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function followers(): array
    {
        return [
            'holder column keeps marker' => [
                "- a\n  - b\n    {% c\n    d %}\n    - z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n{% c\nd %}\n        <ul>\n          <li>z</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'shallower marker opens sibling' => [
                "- a\n  - b\n  {% c\nd %}\n  - z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n{% c\nd %}</li>\n      <li>z</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'accepted comment remains invisible' => [
                "- a\n  - b\n    {% c\n     d %}\n    - z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n\n        <ul>\n          <li>z</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('followers')]
    public function testRefusedCommentKeepsOnlyItsParagraphFollower(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
