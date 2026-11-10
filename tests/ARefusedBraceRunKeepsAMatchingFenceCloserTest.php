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
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>wa\n%% c\n</code></pre>",
        ];

        yield 'tilde follower keeps a definition line as paragraph text' => [
            ":: t\n: +\n{.x\n.y}\n~~~~\nwa\n[foo]: /u\n~~~~\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>wa\n[foo]: /u\n</code></pre>",
        ];

        foreach (['```', '~~~~'] as $fence) {
            yield "description $fence" => [
                ":: t\n: +\n{.x\n.y}\n$fence\nwa\n$fence\n",
                $fence === '```'
                    ? "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>wa\n</code></pre>"
                    : "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>wa\n</code></pre>",
            ];
        }
    }

    #[DataProvider('examples')]
    public function testMatchingCloserRemainsInAttachedParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
