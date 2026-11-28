<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve#6975: an `<img>` without `src` keeps the image (C2-M-050), which the writer reports with `image-src-unknown`.
 */
final class ImageSrcUnknownTest extends TestCase
{
    public function testAnImageWithoutSrcKeepsItsFields(): void
    {
        $imported = Carve::importHtml('<p>a <img alt="A" title="T" class="c"> b</p>');
        $this->assertSame(
            ['type' => 'image', 'alt' => 'A', 'tooltip' => 'T', 'attrs' => ['classes' => ['c']]],
            $imported->document['children'][0]['children'][1],
        );
        $this->assertSame("a ![A]( \"T\"){.c} b\n", $imported->source);
        $this->assertSame(['image-src-unknown'], $imported->codes());
    }

    public function testADeniedSrcStillGivesTheAltText(): void
    {
        $imported = Carve::importHtml('<p><img src="javascript:x" alt="A"></p>');
        $this->assertSame("A\n", $imported->source);
        $this->assertSame(['import-destination-denied'], $imported->codes());
    }

    public function testNeitherAltNorSrcReportsBoth(): void
    {
        $imported = Carve::importHtml('<p><img></p>');
        $this->assertSame("![]()\n", $imported->source);
        $this->assertSame(['image-alt-unknown', 'image-src-unknown'], $imported->codes());
    }
}
