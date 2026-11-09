<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AWrappedBraceRunInsideAnOpenCodeSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'document' => [
            "p\n `open\n {.x\n .y}\n ```\n z\n",
            "<p>p\n<code>open\n{.x\n.y}</code></p>\n<pre><code>z\n</code></pre>",
        ];

        yield 'item' => [
            "- p\n  `open\n  {.x\n  .y}\n  ```\n  z\n",
            "<ul>\n  <li>p\n<code>open\n{.x\n.y}</code>\n    <pre><code>z\n</code></pre>\n  </li>\n</ul>",
        ];

        yield 'quote' => [
            "> p\n> `open\n> {.x\n> .y}\n> ```\n> z\n",
            "<blockquote>\n  <p>p\n<code>open\n{.x\n.y}</code></p>\n  <pre><code>z\n</code></pre>\n</blockquote>",
        ];

        yield 'shifted description body' => [
            ":: t\n: p\n  `open\n   {.x\n   .y}\n  ```\n  z\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>p\n<code>open\n{.x\n.y}</code></p>\n    <pre><code>z\n</code></pre>\n  </dd>\n</dl>",
        ];

        yield 'closed span still refuses the run' => [
            "p\n `open`\n {.x\n .y}\n ```\n z\n",
            "<p>p\n<code>open</code>\n{.x\n.y}\n<code>\nz</code></p>",
        ];
    }

    #[DataProvider('cases')]
    public function testCodeSpanControlsWhetherTheBraceRunStarts(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
