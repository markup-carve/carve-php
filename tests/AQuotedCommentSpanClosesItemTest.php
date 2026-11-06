<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The quote's open comment span leaves no paragraph for an item to continue lazily.
 */
final class AQuotedCommentSpanClosesItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'quote on marker line' => [
                "- > %%%\n  > k\nafter\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>",
            ],
            'attached quote' => [
                "- > +\n  > %%%\n  > k\nafter\n",
                "<ul>\n  <li>\n    <blockquote>\n      <blockquote>\n\n      </blockquote>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>",
            ],
            'longer comment run' => [
                "- > %%%%\n  > k\nafter\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testUnmarkedFollowerLeavesItem(string $source, string $expected): void
    {
        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
