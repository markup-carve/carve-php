<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class DigitLeadingExplicitIdentifiersTest extends TestCase
{
    public function testExplicitIdsAndClassesAcceptAsciiDigitFirst(): void
    {
        $html = CarveConverter::create()->convert("[x]{.123}\n[y]{#7-x}\n");
        self::assertStringContainsString('class="123"', $html);
        self::assertStringContainsString('id="7-x"', $html);
    }

    public function testOtherIdentifierGrammarsAreNotWidened(): void
    {
        $carve = CarveConverter::create();
        foreach (["[x]{12=v}\n", "[x]{12}\n", ":1[x]\n"] as $source) {
            self::assertStringContainsString(trim($source), $carve->convert($source));
        }
    }

    public function testDigitLeadingBareTypeRoundTripsAsGenericDiv(): void
    {
        $carve = CarveConverter::create();
        $source = "::: 123\nbody\n:::\n";
        self::assertStringContainsString('<div class="123">', $carve->convert($source));
        self::assertSame($source, CarveConverter::toCarve($source));
    }

    public function testHtmlImportPreservesDigitLeadingIdsAndClasses(): void
    {
        $source = (new HtmlToCarve())->convert('<p id="123" class="7-x">x</p>');
        self::assertStringContainsString('{#123 .7-x}', $source);
        self::assertStringContainsString('id="123" class="7-x"', CarveConverter::create()->convert($source));
    }
}
