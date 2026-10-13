<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class ImageAltBlockMarkersSurviveNativeExportTest extends TestCase
{
    public function testContinuationMarkersRetainTheirLiteralAltText(): void
    {
        $converter = new CarveConverter();
        $writer = new CarveRenderer();
        foreach (
            [
                '{%%}![a
\\# b](u)',
                '{%%}![a
\\::: b](u)',
                '{%%}![a
\\> b](u)',
                '{%%}![a
\\- b](u)',
                '{%%}![a
1\\. b](u)',
                '{%%}![a
\\%% b](u)',
                '{%%}![a
\\[r\\]: b](u)',
            ] as $source
        ) {
            $written = $writer->render($converter->parse($source));
            $this->assertSame($converter->convert($source), $converter->convert($written), $source);
        }
    }
}
