<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownBlankMarkerLineItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideItems(): array
    {
        return [
            'item opening on a blank marker line' => ["-   \n  foo\n", "- foo\n"],
            'empty item inside a paragraph' => [";\n* \n%\n", ";\n*\n%\n"],
            'ordered item with a tab' => ["1.\t\n   foo\n", "1. foo\n"],
            'lone empty item' => ["- \n", "- {empty}\n"],
        ];
    }

    #[DataProvider('provideItems')]
    public function testFidelityReportDoesNotThrow(string $markdown, string $carve): void
    {
        $converter = new MarkdownToCarve();
        $result = $converter->convertWithFidelityReport($markdown);

        self::assertSame($converter->convert($markdown), $result->value);
        self::assertSame($carve, $result->value);
    }
}
