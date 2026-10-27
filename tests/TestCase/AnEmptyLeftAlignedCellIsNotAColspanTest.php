<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lone `<` is a colspan marker in every space-padding form (PART 9 §5 T4).
 */
class AnEmptyLeftAlignedCellIsNotAColspanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRows(): array
    {
        return [
            'glued after a cell' => [
                "| a |< |\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
            'glued alone' => [
                "|< |\n",
                '<tr><td></td></tr>',
            ],
            'glued with a continuation' => [
                "| a |< |\n+ b | c |\n",
                '<tr><td colspan="2">a b c</td></tr>',
            ],
            'bare marker stays a colspan' => [
                "| a |<|\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
            'spaced marker stays a colspan' => [
                "| a | < |\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
            'marker glued to the closing pipe stays a colspan' => [
                "| a | <|\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
        ];
    }

    #[DataProvider('provideRows')]
    public function testRow(string $source, string $row): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString($row, $html);
    }

    public function testTheWriterSpellingReadsBackAsWritten(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "| a | < |\n"), (new CarveConverter())->toCarve("| a |< |\n"));
    }
}
