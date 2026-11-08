<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5511: a lazy line the walk framed inside a quote keeps its own text when a paragraph reads it.
 */
final class ALazyLineAfterARefusedNotePlusKeepsItsTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'the reproduction' => [
            "[^a]: +\n+\n\n  > q\nlazy\n",
            "<p>&gt; q\nlazy</p>",
        ];

        yield 'a later note opening the quote' => [
            "[^a]: +\n+\n\n  [^b]: > q\nlazy\n",
            "<p>[^b]: &gt; q\nlazy</p>",
        ];

        yield 'no blank before the quote' => [
            "[^a]: +\n+\n   > q\nlazy\n",
            "<p>&gt; q\nlazy</p>",
        ];

        yield 'a nested quote and two lazy lines' => [
            "[^a]: +\n+\n\n   > q\n     > r\nlazy\nmore\n",
            "<p>&gt; q\n&gt; r\nlazy\nmore</p>",
        ];

        yield 'an item host' => [
            "p\n\n- +\n+\n+\n\n\n    [^b]: > q\nlazy\n",
            "<p>p</p>\n<ul>\n  <li></li>\n</ul>\n<p>[^b]: &gt; q\nlazy</p>",
        ];
    }

    #[DataProvider('cases')]
    public function testLazyText(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
