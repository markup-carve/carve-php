<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class CaptionCodeWithLeadingSpaceFormatsTest extends TestCase
{
    public function testParsedCaptionCodeFormatsAndKeepsItsValue(): void
    {
        $source = "||\n^ `\n ^\n";
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        $html = CarveConverter::create();
        self::assertSame($html->convert($source), $html->convert($formatted));
    }
}
