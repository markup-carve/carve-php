<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class FormatterKeepsUnclosedCodeAcrossColonLineTest extends TestCase
{
    public function testFormattingPreservesTheCodeAndStopsChanging(): void
    {
        $source = "`\n :::\nc\n";
        $first = CarveConverter::toCarve($source);
        $second = CarveConverter::toCarve($first);

        self::assertSame($first, $second);
        $html = CarveConverter::create();
        self::assertSame($html->convert($source), $html->convert($first));
    }
}
