<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AListAfterAnAttachedQuoteMissesItsFormerHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'description first block and own line plus' => [
            ":: t\n: +\n1. li\n+\n> q\n- other\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>li</li>\n    </ol>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n<ul>\n  <li>other</li>\n</ul>",
        ];

        yield 'description paragraph and own line plus' => [
            ":: t\n: p\n+\n> q\n- other\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>p</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n<ul>\n  <li>other</li>\n</ul>",
        ];

        yield 'ordinary quote still folds the marker' => [
            "> q\n- other\n",
            "<blockquote><p>q\n- other</p></blockquote>",
        ];
    }

    #[DataProvider('cases')]
    public function testListOwnershipAfterQuote(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
