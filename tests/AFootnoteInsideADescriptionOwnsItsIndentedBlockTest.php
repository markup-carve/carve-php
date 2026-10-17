<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AFootnoteInsideADescriptionOwnsItsIndentedBlockTest extends TestCase
{
    public function testHeadingAfterBlankBelongsToFootnote(): void
    {
        $source = "x[^c]\n\n:: t\n: [^c]: para\n\n    # H\n";
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($source, $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString('<dd></dd>', $html);
        self::assertStringContainsString('<h1 id="H">H</h1>', $html);
        self::assertStringNotContainsString('<dd><h1', $html);
    }

    public function testFootnoteBlocksGainTheirBlankSeparator(): void
    {
        foreach (['# H', '> q'] as $block) {
            $source = "x[^c]\n\n:: t\n: [^c]: para\n    $block\n";
            $expected = "x[^c]\n\n:: t\n: [^c]: para\n\n    $block\n";
            self::assertSame($expected, CarveConverter::toCarve($source));
            self::assertSame($expected, CarveConverter::toCarve($expected));
        }
    }
}
