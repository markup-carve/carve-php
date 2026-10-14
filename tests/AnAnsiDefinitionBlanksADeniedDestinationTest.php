<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnAnsiDefinitionBlanksADeniedDestinationTest extends TestCase
{
    public function testUnusedDefinitionKeepsItsLineWithoutTheDeniedUrl(): void
    {
        $output = CarveConverter::ansi()->convert("Text.\n\n[x]: /u\n\n[y]: javascript:alert(3)\n");

        self::assertStringContainsString('[x]: /u', $output);
        self::assertStringContainsString('[y]:', $output);
        self::assertStringNotContainsString('javascript:', $output);
    }
}
