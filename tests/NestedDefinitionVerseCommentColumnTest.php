<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NestedDefinitionVerseCommentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'quote keeps overindented percent verses' => [
            "> :: u\n> : ::: |\n>       %%%\n>       %%%\nz\n",
            "<blockquote>\n  <dl>\n    <dt>u</dt>\n    <dd>\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;&nbsp;&nbsp;%%%<br>\n&nbsp;&nbsp;&nbsp;&nbsp;%%%<br>\nz</p>\n      </div>\n    </dd>\n  </dl>\n</blockquote>",
        ];

        yield 'item keeps overindented percent verses' => [
            "- :: u\n  : ::: |\n        %%%\n        %%%\nz\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>u</dt>\n      <dd>\n        <div class=\"line-block\">\n          <p>&nbsp;&nbsp;&nbsp;&nbsp;%%%<br>\n&nbsp;&nbsp;&nbsp;&nbsp;%%%<br>\nz</p>\n        </div>\n      </dd>\n    </dl>\n  </li>\n</ul>",
        ];

        yield 'shifted item keeps a matching comment span' => [
            "  - :: u\n    : ::: |\n        %%%\n        %%%\nz\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>u</dt>\n      <dd>\n        <div class=\"line-block\">\n\n        </div>\n      </dd>\n    </dl>\n  </li>\n</ul>\n<p>z</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
