<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AVerbatimValueLineIsSpelledOrRefusedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function spellings(): array
    {
        return [
            'definition last line' => ["` a\n[d]: u `\n", "` a\n[d]: u `\n"],
            'definition last line in a footnote' => ["y[^n]\n\n[^n]: ` a\n  [d]: u `\n", "y[^n]\n\n[^n]: ` a\n  [d]: u `\n"],
            'marker in a quoted item' => ["> - `a\n- b`\n", "> - `a\n- b`\n"],
            'a join that is the first space stays unclosed' => ["| a | b |\n+ p | `` |\n+   | a | ` |\n", "| a | b |\n+ p | `` |\n+ | a | ` |\n"],
        ];
    }

    #[DataProvider('spellings')]
    public function testTheValueReadsBack(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);

        $this->assertSame($expected, $written);
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($written));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtureRows(): array
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../spec/tests/fixtures/verbatim-opener-line-fmt.json'), true);
        $rows = [];
        foreach ($fixture['rows'] as $row) {
            if (isset($row['source'])) {
                $rows[$row['id']] = [$row['source']];
            }
        }

        return $rows;
    }

    #[DataProvider('fixtureRows')]
    public function testEverySpelledRowIsWrittenBack(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
