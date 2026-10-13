<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AContinuationAfterARowspanMarkerJoinsTheOriginTest extends TestCase
{
    #[DataProvider('rows')]
    public function testContinuationOwnership(string $source, string $firstRow, string $secondRow): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString($firstRow, $html);
        self::assertStringContainsString($secondRow, $html);
    }

    public function testUnconsumedSpanMarkerKeepsItsContinuation(): void
    {
        $html = CarveConverter::create()->convert("| a | < |\n| ^ | ^ |\n+ more | x |\n");

        self::assertStringContainsString('more', $html);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rows(): iterable
    {
        yield 'continuation after span' => [
            "| a | b |\n| ^ | c |\n+ more | d |\n",
            '<tr><td rowspan="2">a more</td><td>b</td></tr>',
            '<tr><td>c d</td></tr>',
        ];

        yield 'continuation before span' => [
            "| a | b |\n+ more | d |\n| ^ | c |\n",
            '<tr><td rowspan="2">a more</td><td>b d</td></tr>',
            '<tr><td>c</td></tr>',
        ];

        yield 'inline run crosses the span continuation' => [
            "| *a | b |\n| ^ | c |\n+ b* | d |\n",
            '<tr><td rowspan="2"><strong>a b</strong></td><td>b</td></tr>',
            '<tr><td>c d</td></tr>',
        ];
    }
}
