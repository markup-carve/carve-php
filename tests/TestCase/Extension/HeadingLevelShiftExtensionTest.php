<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\HeadingLevelShiftExtension;
use PHPUnit\Framework\TestCase;

class HeadingLevelShiftExtensionTest extends TestCase
{
    public function testShiftByOne(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\n## Heading 2\n\n### Heading 3");

        // Headings are shifted, section wrapping preserved
        $this->assertStringContainsString('<h2 id="heading-1">Heading 1</h2>', $result);
        $this->assertStringContainsString('<h3 id="heading-2">Heading 2</h3>', $result);
        $this->assertStringContainsString('<h4 id="heading-3">Heading 3</h4>', $result);
    }

    public function testShiftByTwo(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("# Heading 1\n\n## Heading 2");

        $this->assertStringContainsString('<h3 id="heading-1">Heading 1</h3>', $result);
        $this->assertStringContainsString('<h4 id="heading-2">Heading 2</h4>', $result);
    }

    public function testCapsAtH6(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("##### Heading 5\n\n###### Heading 6");

        // h5 + 2 = h6 (capped), h6 + 2 = h6 (capped)
        $this->assertStringContainsString('<h6 id="heading-5">Heading 5</h6>', $result);
        $this->assertStringContainsString('<h6 id="heading-6">Heading 6</h6>', $result);
    }

    public function testZeroShiftDoesNothing(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 0));

        $result = $converter->convert('# Heading 1');

        // Zero shift - default section-wrapped rendering
        $this->assertStringContainsString('<h1 id="heading-1">Heading 1</h1>', $result);
    }

    public function testPreservesAttributes(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // In djot, attributes go on line before the heading
        $result = $converter->convert("{.custom-class}\n# Heading");

        $this->assertStringContainsString('<h2 class="custom-class" id="heading">Heading</h2>', $result);
    }

    public function testPreservesExplicitId(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // In djot, attributes go on line before the heading
        $result = $converter->convert("{#my-id}\n# Heading");

        $this->assertStringContainsString('<h2 id="my-id">Heading</h2>', $result);
    }

    public function testShiftClampedToValidRange(): void
    {
        // Shift > 5 should be clamped to 5
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 10));

        $result = $converter->convert('# Heading 1');

        // h1 + 5 = h6
        $this->assertStringContainsString('<h6 id="heading-1">Heading 1</h6>', $result);
    }

    public function testNegativeShiftClampedToZero(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: -1));

        $result = $converter->convert('# Heading 1');

        // Negative shift clamped to 0 - default section-wrapped rendering
        $this->assertStringContainsString('<h1 id="heading-1">Heading 1</h1>', $result);
    }

    public function testWorksWithSectionWrapping(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\nContent");

        // Headings are flat; the shifted level is applied
        $this->assertStringContainsString('<h2 id="heading-1">Heading 1</h2>', $result);
        $this->assertStringContainsString('<p>Content</p>', $result);
    }

    public function testWorksWithMarkdownRenderer(): void
    {
        $converter = CarveConverter::markdown();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\n## Heading 2");

        // Markdown output with shifted levels
        $this->assertStringContainsString('## Heading 1', $result);
        $this->assertStringContainsString('### Heading 2', $result);
    }

    public function testWorksWithPlainTextRenderer(): void
    {
        $converter = CarveConverter::plainText();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\nSome text.");

        // Plain text just renders content
        $this->assertStringContainsString('Heading 1', $result);
        $this->assertStringContainsString('Some text.', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    public function testRenderDoesNotMutateCallerOwnedDocument(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $document = $converter->parse('# Heading 1');

        $first = $converter->render($document);
        $second = $converter->render($document);

        $this->assertStringContainsString('<h2 id="heading-1">Heading 1</h2>', $first);
        $this->assertStringContainsString('<h2 id="heading-1">Heading 1</h2>', $second);
        $this->assertStringNotContainsString('<h3 id="heading-1">Heading 1</h3>', $second);
    }

    public function testExtensionInstanceCanBeReusedAcrossConverters(): void
    {
        $extension = new HeadingLevelShiftExtension(shift: 1);

        $roundTripConverter = new CarveConverter(roundTripMode: true);
        $roundTripConverter->addExtension($extension);

        $normalConverter = new CarveConverter();
        $normalConverter->addExtension($extension);

        $html = $roundTripConverter->convert('# Heading 1');

        $this->assertStringContainsString('data-djot-source-level="1"', $html);
    }
}
