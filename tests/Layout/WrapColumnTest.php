<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Layout;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-L-020, C2-L-048 (carve#6712): a wrap of a cell that covers several columns records its column.
 */
final class WrapColumnTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<array{0: int, 1: int, 2: int}>}>
     */
    public static function sources(): array
    {
        return [
            'fragments in two rows' => ["| a | < |\n+ | b |\n+ e | |\n", [[1, 4, 1], [2, 2, 0]]],
            'fragments in one row' => ["| a | < |\n+ b | c |\n", [[1, 2, 0], [1, 4, 1]]],
            'three columns' => ["| a | < | < |\n+ | | b |\n+ c | d | |\n", [[1, 6, 2], [2, 2, 0], [2, 4, 1]]],
            'one column keeps no col' => ["| a | b |\n+ c | d |\n", []],
        ];
    }

    /**
     * @param string $source
     * @param array<array{0: int, 1: int, 2: int}> $wraps Line, offset and col of each wrap of the first cell.
     */
    #[DataProvider('sources')]
    public function testWrapsRecordTheColumnAndFormatKeepsTheSource(string $source, array $wraps): void
    {
        $cell = '/children/0/children/0/children/0/children/0';
        $entry = array_column(Carve::parseWithLayout($source)->layout['nodes'], null, 'path')[$cell] ?? [];
        $actual = array_map(
            static fn (array $wrap): array => [$wrap['line'], $wrap['offset'], $wrap['col'] ?? null],
            $entry['wraps'] ?? [],
        );
        if ($wraps === []) {
            foreach ($actual as $wrap) {
                $this->assertNull($wrap[2]);
            }
        } else {
            $this->assertSame($wraps, $actual);
        }
        $this->assertSame($source, Carve::format($source));
    }

    public function testColumnOutsideTheCellDropsTheWraps(): void
    {
        $source = "| a | < |\n+ b | c |\n";
        $result = Carve::parseWithLayout($source);
        $layout = $result->layout;
        foreach ($layout['nodes'] as $k => $node) {
            if (isset($node['wraps'])) {
                $layout['nodes'][$k]['wraps'][1]['col'] = 2;
            }
        }
        $written = Carve::write($result->tree, $layout);
        $this->assertSame("| a b c | < |\n+ | |\n", $written);
    }
}
