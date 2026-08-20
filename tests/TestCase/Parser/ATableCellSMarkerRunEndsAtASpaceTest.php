<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §5 T11: a table cell's marker run ends at one literal space.
 */
class ATableCellSMarkerRunEndsAtASpaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function markerRunProvider(): array
    {
        return [
            'a spaced kind marker is a header' => [
                "|= a |\n",
                '<th scope="col">a</th>',
                [],
            ],
            'a glued kind marker is content' => [
                "|=a |\n",
                '<td>=a</td>',
                ['<th'],
            ],
            'a spaced attribute block binds' => [
                "|{#x} =R |\n",
                '<td id="x">=R</td>',
                [],
            ],
            'a glued attribute block is content' => [
                "|{#x}=R|\n",
                '<td>{<span class="tag"><strong>#x</strong></span>}=R</td>',
                ['id="x"'],
            ],
            'a rejected alignment run takes the kind marker with it' => [
                "|=<< Note |\n",
                '<td>=&lt;&lt; Note</td>',
                ['<th'],
            ],
            'the closing pipe does not terminate a run' => [
                "|=|\n",
                '<td>=</td>',
                ['<th'],
            ],
            'a tab does not terminate a run' => [
                "|=\th |\n",
                "<td>=\th</td>",
                ['<th'],
            ],
            'an unclosed brace after the marker is no block, and no run' => [
                "|={.x a |\n",
                '<td>={.x a</td>',
                ['<th'],
            ],
            'an empty payload after the marker is no block either' => [
                "|={} a |\n",
                '<td>={} a</td>',
                ['<th'],
            ],
            'a cell with no run is unchanged' => [
                "| a |\n",
                '<td>a</td>',
                [],
            ],
            'an escaped kind marker reaches inline parsing' => [
                "|\\= a |\n",
                '<td>= a</td>',
                ['<th'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     * @param list<string> $absent
     */
    #[DataProvider('markerRunProvider')]
    public function testAMarkerRunEndsAtASpace(string $source, string $expected, array $absent): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expected, $html);
        foreach ($absent as $fragment) {
            self::assertStringNotContainsString($fragment, $html);
        }
    }
}
