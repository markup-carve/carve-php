<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ACaptionCodeSpanEndsAtALaterFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'quote caption' => [
            "> quote\n^ `open\n {.x\n .y}\n ```\n z\n",
            "<figure>\n  <blockquote><p>quote</p></blockquote>\n  <figcaption><code>open\n {.x\n .y}</code></figcaption>\n</figure>\n<pre><code>z\n</code></pre>",
        ];

        yield 'table caption' => [
            "|= a |\n| b |\n^ `open\n {.x\n .y}\n ```\n z\n",
            "<table>\n  <caption><code>open\n {.x\n .y}</code></caption>\n  <thead>\n    <tr><th scope=\"col\">a</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>\n<pre><code>z\n</code></pre>",
        ];
    }

    #[DataProvider('cases')]
    public function testCaptionParagraphEndsAtFence(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
