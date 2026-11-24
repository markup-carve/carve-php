<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpanMarksAndPairsTest extends TestCase
{
    /**
     * @var array
     */
    private const PAIR_RULES = [
        'deprecated-bare-underline', 'deprecated-bare-strike', 'deprecated-bare-highlight',
        'markdown-strikethrough-double-tilde', 'markdown-strong-double-star',
    ];

    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function spans(): array
    {
        return [
            'C2-W-145 a colspan in the head' => [
                '<table><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table>',
                "|= H |= < |\n| a | b |\n",
                [],
            ],
            'C2-W-145 a colspan to the end of the row' => [
                '<table><tr><td>a</td><td colspan="3">b</td></tr></table>', "| a | b | < | < |\n", [],
            ],
            'C2-W-145 a rowspan in the last column' => [
                '<table><tr><td>x</td><td rowspan="2">a</td></tr><tr><td>c</td></tr></table>', "| x | a |\n| c | ^ |\n", [],
            ],
            'C2-W-145 two rowspans side by side' => [
                '<table><tr><td rowspan="3">a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>d</td></tr>'
                . '<tr><td>e</td><td>f</td></tr></table>',
                "| a | b | c |\n| ^ | d | ^ |\n| ^ | e | f |\n",
                [],
            ],
            'C2-W-145 a cell with both counts loses its row span' => [
                '<table><tr><td rowspan="2" colspan="2">a</td><td>b</td></tr><tr><td>c</td></tr>'
                . '<tr><td>1</td><td>2</td><td>3</td></tr></table>',
                "| a | < | b |\n| c |\n| 1 | 2 | 3 |\n",
                ['/children/0/children/0/children/0/children/0/rowspan'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('spans')]
    public function testImportedSpanIsWrittenWithMarks(string $html, string $source, array $losses): void
    {
        $result = Carve::importDocument($html, ['from' => 'html']);
        $this->assertSame($source, $result->source);
        $paths = [];
        foreach ($result->diagnostics as $entry) {
            if (str_ends_with($entry->code, 'unspellable')) {
                $paths[] = $entry->path;
            }
        }
        $this->assertSame($losses, $paths);
    }

    public function testSuppliedTreeWithCountsReadsBackAsTheGrid(): void
    {
        $cell = static fn (string $value, array $extra = []): array => [

            'type' => 'table_cell',
            'header' => false,
            'children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $value]]]],
        ] + $extra;
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'table',
                    'columns' => [['type' => 'table_column'], ['type' => 'table_column']], 'children' => [
                        [

                            'type' => 'table_body',
                            'children' => [
                                ['type' => 'table_row', 'children' => [$cell('m', ['colspan' => 2])]],
                                ['type' => 'table_row', 'children' => [$cell('a', ['rowspan' => 2]), $cell('b')]],
                                ['type' => 'table_row', 'children' => [$cell('c')]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $written = Writer::writeWithDiagnostics($tree);
        $this->assertSame("| m | < |\n| a | b |\n| ^ | c |\n", $written->source);
        $this->assertSame([], $written->diagnostics);
        $html = Carve::render($written->source);
        $this->assertStringContainsString('<td colspan="2">m</td>', $html);
        $this->assertStringContainsString('<td rowspan="2">a</td>', $html);
    }

    public function testOnlyTheCountsWithoutMarksTakeThemInATableThatHoldsMarks(): void
    {
        $tree = TreeEncoder::decoded(Carve::parse("| a | < | b |\n| c | d | e |\n| f | g | h |\n| i | j |\n"));
        $rows = &$tree['children'][0]['children'][0]['children'];
        $rows[1]['children'] = [$rows[1]['children'][0] + ['colspan' => 2], $rows[1]['children'][2]];
        $rows[2]['children'][1] += ['rowspan' => 2];
        unset($rows);
        $written = Writer::writeWithDiagnostics(self::withoutPositions($tree));
        $this->assertSame("| a | < | b |\n| c | < | e |\n| f | g | h |\n| i | ^ | j |\n", $written->source);
        $this->assertSame([], $written->diagnostics);
    }

    public function testACountWithSomeOfItsMarksGetsOnlyTheRest(): void
    {
        $cell = static fn (string $value, array $extra = []): array => [

            'type' => 'table_cell',
            'header' => false,
            'children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $value]]]],
        ] + $extra;
        $mark = static fn (string $span): array => ['type' => 'table_cell', 'header' => false, 'span' => $span, 'children' => []];
        $table = static fn (int $columns, array $rows): array => [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => array_fill(0, $columns, ['type' => 'table_column']),
                    'children' => [
                        [

                            'type' => 'table_body',
                            'children' => array_map(static fn (array $cells): array => [
                                'type' => 'table_row',
                                'children' => $cells,
                            ], $rows),
                        ],
                    ],
                ],
            ],
        ];
        $written = Writer::writeWithDiagnostics($table(3, [[$cell('a', ['colspan' => 3]), $mark('colspan')]]));
        $this->assertSame("| a | < | < |\n", $written->source);
        $written = Writer::writeWithDiagnostics($table(2, [
            [$cell('a', ['rowspan' => 3]), $cell('b')], [$mark('rowspan'), $cell('c')], [$cell('d')],
        ]));
        $this->assertSame("| a | b |\n| ^ | c |\n| ^ | d |\n", $written->source);
        $this->assertSame([], $written->diagnostics);
    }

    /**
     * @param mixed $node
     *
     * @return mixed
     */
    private static function withoutPositions(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        unset($node['pos']);

        return array_map(self::withoutPositions(...), $node);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pairs(): array
    {
        return [
            'C2-W-146 a tilde pair' => ['~s~', "\\~s~\n"],
            'C2-W-146 an underscore pair' => ['_u_', "\\_u_\n"],
            'C2-W-146 an equals pair' => ['=x=', "\\=x=\n"],
            'C2-W-146 a double tilde pair' => ['~~s~~', "\\~\\~s~~\n"],
            'C2-W-146 a double star pair' => ['**b**', "\\*\\*b**\n"],
            'C2-W-146 two pairs in a sentence' => ['a _u_ and =x= b', "a \\_u_ and \\=x= b\n"],
            'C2-W-146 an underscore inside a word' => ['a_b_c', "a_b_c\n"],
            'C2-I-050 a tilde with a combining mark' => ["x ~\u{301}a~ y", "x ~\u{301}a~ y\n"],
        ];
    }

    #[DataProvider('pairs')]
    public function testWrittenTextPassesThePairRules(string $value, string $source): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]],
            ],
        ];
        $written = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $written->source);
        $this->assertSame([], $written->diagnostics);
        $this->assertSame([], $this->pairFindings($written->source));
        $this->assertSame($source, Writer::format($source));
    }

    public function testAPairAcrossASoftBreakIsEscaped(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'p {_='], ['type' => 'soft_break'], ['type' => 'text', 'value' => '{_= q'],
                    ],
                ],
            ],
        ];
        $written = Writer::writeWithDiagnostics($tree)->source;
        $this->assertSame([], $this->pairFindings($written));
        $this->assertSame($written, Writer::format($written));
    }

    public function testFmtKeepsAnAuthorEscapeOfAPair(): void
    {
        $this->assertSame("\\_u_ \\*\\*b**\n", Writer::format("\\_u_ \\*\\*b**\n"));
        $this->assertSame("x \\_y_\n", Writer::format("x _y_\n"));
    }

    /**
     * @param string $source
     *
     * @return array<string>
     */
    private function pairFindings(string $source): array
    {
        $codes = [];
        foreach (Carve::lint($source, ['upgrade' => true]) as $entry) {
            if (in_array($entry->code, self::PAIR_RULES, true)) {
                $codes[] = $entry->code;
            }
        }

        return $codes;
    }
}
