<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotEscapedEmphasisTest extends TestCase
{
    public function testEscapedDelimitersKeepTheirEmphasis(): void
    {
        foreach (
            [
                ['_\\__', '<p><em>_</em></p>'],
                ['_ab\\_c_', '<p><em>ab_c</em></p>'],
                ["_\u{00a0}a\u{00a0}_", '<p><em>&nbsp;a&nbsp;</em></p>'],
                ['a_b\\_c_d', '<p>a<em>b_c</em>d</p>'],
                ['\\_plain\\_', '<p>_plain_</p>'],
                ["_a\n\nb_", "<p>_a</p>\n<p>b_</p>"],
            ] as [$source, $expected]
        ) {
            $written = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($written), "\n"));
        }
    }

    public function testEscapedNewlinesCannotHideParagraphBoundaries(): void
    {
        foreach (["_a\\\n\nb_", "_a\\\n  \nb_", "a_b\\\n\nc_d"] as $source) {
            $this->assertSame(str_replace('_', '\\_', $source), (new DjotToCarve())->convert($source));
        }
    }
}
