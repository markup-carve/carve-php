<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyDefinitionLineBlockSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'empty span releases below-column follower' => [
            ":: u\n: ::: |\n   %%%\n   %%%\nz\n",
            "<dl>\n  <dt>u</dt>\n  <dd>\n    <div class=\"line-block\">\n\n    </div>\n  </dd>\n</dl>\n<p>z</p>",
        ];

        yield 'prior stanza retains below-column follower' => [
            ":: u\n: ::: |\n  x\n  %%%\n  %%%\nz\n",
            "<dl>\n  <dt>u</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>x<br>\nz</p>\n    </div>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
