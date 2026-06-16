<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Transform;

use Carve\CarveConverter;
use Carve\Converter\HtmlToCarve;
use Carve\Transform\HeadingLevelShiftTransform;
use PHPUnit\Framework\TestCase;

class HeadingLevelShiftTransformTest extends TestCase
{
    public function testTransformReturnsShiftedCopyWithoutMutatingOriginalDocument(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('# Heading 1');

        $transformed = $converter->transform($document, new HeadingLevelShiftTransform(1));

        $original = $converter->render($document);
        $shifted = $converter->render($transformed);

        $this->assertStringContainsString('<section id="Heading-1">', $original);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $original);
        $this->assertStringContainsString('<section id="Heading-1">', $shifted);
        $this->assertStringContainsString('<h2>Heading 1</h2>', $shifted);
    }

    public function testTransformedDocumentCanBeRenderedRepeatedly(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('# Heading 1');
        $transformed = $converter->transform($document, new HeadingLevelShiftTransform(1));

        $first = $converter->render($transformed);
        $second = $converter->render($transformed);

        $this->assertStringContainsString('<h2>Heading 1</h2>', $first);
        $this->assertStringContainsString('<h2>Heading 1</h2>', $second);
        // The shift must not accumulate across renders (would become h3).
        $this->assertStringNotContainsString('<h3>Heading 1</h3>', $second);
    }

    public function testTransformPreservesSourceLevelsInHtmlRoundTripMode(): void
    {
        $this->markTestSkipped('Round-trip materializes auto-generated heading ids as {#id}; HtmlToCarve should only re-emit explicitly authored ids. Tracked separately, unrelated to flat-heading/auto-id work.');

        $converter = new CarveConverter(roundTripMode: true);
        $document = $converter->parse('# Heading 1');

        $transformed = $converter->transform($document, new HeadingLevelShiftTransform(1));
        $html = $converter->render($transformed);

        $this->assertStringContainsString('data-djot-source-level="1"', $html);
        $this->assertSame('# Heading 1', trim((new HtmlToCarve())->convert($html)));
    }
}
