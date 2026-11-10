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
        yield 'a' => [":: t\n: +\n  {% a\n  %}\n  ```\n  ```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{% a\n%}</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>"];
        yield '#x' => [":: t\n: +\n  {% #x\n  %}\n  ```\n  ```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{% <span class=\"tag\"><strong>#x</strong></span>\n%}</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>"];
    }

    #[DataProvider('cases')]
    public function testRefusedCommentRemainsLiteral(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
