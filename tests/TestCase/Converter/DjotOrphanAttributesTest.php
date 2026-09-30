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
                ["After {#id} space\n{.class}\n", '<p>After  space</p>'],
                ["not a [span] {#id}.\n", '<p>not a [span] .</p>'],
                ["{#id .class}\n\nA paragraph\n", '<p>A paragraph</p>'],
                ['[span]{#id}', '<p><span id="id">span</span></p>'],
                ['`x`{.c}', '<p><code class="c">x</code></p>'],
                ['<http://x.y>{.c}', '<p><a href="http://x.y" class="c">http://x.y</a></p>'],
            ] as [$source, $expected]
        ) {
            $carve = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, trim((new CarveConverter())->convert($carve)), $source);
        }
    }
}
