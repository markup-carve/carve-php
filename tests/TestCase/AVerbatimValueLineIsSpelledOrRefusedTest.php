<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AVerbatimValueLineIsSpelledOrRefusedTest extends TestCase
{
    /**
     * Rows the pinned fixture still spells but the current spec does not: there the line opens a new list.
     *
     * @var array<string, string>
     */
    protected const UNSPELLED_AHEAD_OF_PIN = [
        'dot-marker-in-a-bullet-item' => 'carve#4545: a marker between an item\'s columns that differs on an axis opens a new list',
    ];

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
            if (isset($row['source']) && !isset(self::UNSPELLED_AHEAD_OF_PIN[$row['id']])) {
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

    public function testEveryUnspelledRowIsStillSpelledAtThePin(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../spec/tests/fixtures/verbatim-opener-line-fmt.json'), true);
        $spelled = [];
        foreach ($fixture['rows'] as $row) {
            if (isset($row['source'])) {
                $spelled[] = $row['id'];
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::UNSPELLED_AHEAD_OF_PIN), $spelled)), 'The pin no longer spells these rows: delete their UNSPELLED_AHEAD_OF_PIN entries');
    }
}
