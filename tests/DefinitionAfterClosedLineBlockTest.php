<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionAfterClosedLineBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'later paragraph keeps lazy follower' => [
            ":: u\n: ::: |\n  :::\n  [a]\nz\n",
            "<dl>\n  <dt>u</dt>\n  <dd>\n    <div class=\"line-block\">\n\n    </div>\n    <p>[a]\nz</p>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
