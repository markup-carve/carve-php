<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedBraceRunKeepsAMatchingFenceCloserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'comment payload remains inside the kept fence' => [
            ":: t\n: +\n{.x\n.y}\n```\nwa\n%% c\n```\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}\n<code>\nwa\n%% c\n</code></dd>\n</dl>",
        ];

        foreach (['```', '~~~~'] as $fence) {
            yield "description $fence" => [
                ":: t\n: +\n{.x\n.y}\n$fence\nwa\n$fence\n",
                $fence === '```'
                    ? "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}\n<code>\nwa\n</code></dd>\n</dl>"
                    : "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}\n~~~~\nwa\n~~~~</dd>\n</dl>",
            ];
        }
    }

    #[DataProvider('examples')]
    public function testMatchingCloserRemainsInAttachedParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
