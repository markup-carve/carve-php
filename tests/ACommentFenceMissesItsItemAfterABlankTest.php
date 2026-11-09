<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ownership walk ends an item comment when the next line misses the item.
 * A local comment scan cannot carry that line into the item's sibling run.
 */
final class ACommentFenceMissesItsItemAfterABlankTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'unordered item and root comment' => [
            "- b\n\n  %%%\nhidden\n%%%\n- sibling\n",
            "<ul>\n  <li>b</li>\n</ul>\n<p>hidden</p>",
        ];

        yield 'alternate unordered marker' => [
            "* b\n\n  %%%\nhidden\n%%%\n* sibling\n",
            "<ul>\n  <li>b</li>\n</ul>\n<p>hidden</p>",
        ];

        yield 'ordered marker has a wider content column' => [
            "1. b\n\n   %%%\nhidden\n%%%\n2. sibling\n",
            "<ol>\n  <li>b</li>\n</ol>\n<p>hidden</p>",
        ];

        yield 'same-owner payload and closer preserve the sibling' => [
            "- b\n\n  %%%\n  hidden\n  %%%\n- sibling\n",
            "<ul>\n  <li><p>b</p></li>\n  <li><p>sibling</p></li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheOwnerBoundaryOutranksLocalCommentPairing(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
