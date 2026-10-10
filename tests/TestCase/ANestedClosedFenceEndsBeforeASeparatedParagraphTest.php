<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ANestedClosedFenceEndsBeforeASeparatedParagraphTest extends TestCase
{
    public function testASeparatingBlankEndsTheList(): void
    {
        foreach (['```', '~~~', '```=html'] as $opener) {
            $closer = str_starts_with($opener, '~') ? '~~~' : '```';
            $source = '- - ' . $opener . "\n    " . $closer . "\n\nx\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertMatchesRegularExpression('/<\/ul>\s*<p>x<\/p>\s*$/', $html);
        }
    }

    public function testAnAbuttingLineStillFoldsIntoTheOuterItem(): void
    {
        $html = (new CarveConverter())->convert("- - ```\n    ```\nx\n");
        $this->assertMatchesRegularExpression('/<\/ul>\s*x\s*<\/li>\s*<\/ul>\s*$/', $html);
    }

    public function testFormattingKeepsTheParagraphOutsideTheList(): void
    {
        foreach (["- - ```\n\ntail\n", "- - ```\n+\n\ntail\n"] as $source) {
            $before = (new CarveConverter())->convert($source);
            $formatted = CarveConverter::toCarve($source);
            $this->assertSame($before, (new CarveConverter())->convert($formatted));
            $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        }
    }
}
