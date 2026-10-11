<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RaggedTableFmtTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'ordinary rows' => ["| ~x~ |\n| a | b |\n", "| ~x~ |\n| a | b |\n"],
            // The authored separator row is reproduced (carve#2894), and it
            // grows to the row's cell count: a cell the author did not spell
            // takes the writer's own default width.
            'short body row' => ["| |x |\n|---|\n| y |\n", "| | x |\n|---|---|\n| y |\n"],
            'short header row' => ["| h |\n|---|\n| |x |\n", "| h |\n|---|\n| | x |\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testEveryRowKeepsItsCellCount(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($expected));
    }
}
