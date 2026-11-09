<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedCommentRunInAFirstBlockDescriptionIsLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (['a', '#x'] as $content) {
            yield $content => [
                ":: t\n: +\n  {% $content\n  %}\n  ```\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>{% $content\n%}\n<code>\n</code></dd>\n</dl>",
            ];
        }
    }

    #[DataProvider('cases')]
    public function testRefusedCommentRemainsLiteral(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
