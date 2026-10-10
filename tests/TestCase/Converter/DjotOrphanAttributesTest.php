<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotOrphanAttributesTest extends TestCase
{
    public function testAttributeOwnership(): void
    {
        foreach (
            [
                ["{#id} at beginning\n", '<p> at beginning</p>'],
                ["After {#id} space\n{.class}\n", "<p>After  space\n</p>"],
                ["not a [span] {#id}.\n", '<p>not a [span] .</p>'],
                ["{#id .class}\n\nA paragraph\n", '<p>A paragraph</p>'],
                ["{.a}\n{.b}\n\npara", '<p>para</p>'],
                ["{.a}\n{.b}", ''],
                ["{.a}\n{.b}\n\n[r]: u\n\n[x][r]", '<p><a href="u">x</a></p>'],
                ["{.a}\n{.b}\n[r]: u\n\n[x][r]", '<p><a href="u" class="a b">x</a></p>'],
                ["{.a}\n\n{.b}\n[r]: u\n\n[x][r]", '<p><a href="u" class="b">x</a></p>'],
                ['[span]{#id}', '<p><span id="id">span</span></p>'],
                ['`x`{.c}', '<p><code class="c">x</code></p>'],
                ['<http://x.y>{.c}', '<p><a href="http://x.y" class="c">http://x.y</a></p>'],
            ] as [$source, $expected]
        ) {
            $carve = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, trim((new CarveConverter())->convert($carve)), $source);
        }
    }

    public function testPendingRunsKeepTheirContainers(): void
    {
        foreach (
            [
                ["> {.a}\n> {.b}\n>\n> para", '<blockquote><p>para</p></blockquote>'],
                ["> {.a}\n> {.b}\n\npara", '<blockquote></blockquote><p>para</p>'],
                ["{.a}\n> {.b}\n\n", '<blockquote class="a"></blockquote>'],
                ["{.a}\n> {.b}\n>\n> para", '<blockquote class="a"><p>para</p></blockquote>'],
                ["```\n{.a}\n{.b}\n\npara\n```", "<pre><code>{.a}\n{.b}\n\npara\n</code></pre>"],
            ] as [$source, $expected]
        ) {
            $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
            self::assertSame($expected, preg_replace('/>\s+</', '><', $html), $source);
        }
    }
}
