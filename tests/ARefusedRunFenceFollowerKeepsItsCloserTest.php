<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedRunFenceFollowerKeepsItsCloserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (['{.x' => '.y}', '{#a' => '.b}'] as $first => $second) {
            yield $first => [
                ":: t\n: +\n  $first\n  $second\n  ```\n  ```\n\nend\n",
                "<dl>\n  <dt>t</dt>\n  <dd>$first\n$second\n<code>\n</code></dd>\n</dl>\n<p>end</p>",
            ];
        }

        foreach (['# heading', '- item'] as $payload) {
            yield $payload => [
                ":: t\n: +\n  {.x\n  .y}\n  ```\n  $payload\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}\n<code>\n$payload\n</code></dd>\n</dl>",
            ];
        }
    }

    #[DataProvider('cases')]
    public function testCloserIsPartOfParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
