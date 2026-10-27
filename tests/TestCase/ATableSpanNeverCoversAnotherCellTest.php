<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T5: a span never covers a position another cell owns (carve#3047).
 */
class ATableSpanNeverCoversAnotherCellTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bodyProvider(): array
    {
        return [
            'a ^ under a two-column cell takes the marker beside it' => [
                "|= 2-col header|<|= header|\n|spans 3 rows|foo|bar|\n|^|spans 4 cells|<|\n|^|^|<|\n",
                '<tr><td rowspan="3">spans 3 rows</td><td>foo</td><td>bar</td></tr><tr><td rowspan="2" colspan="2">spans 4 cells</td></tr><tr></tr>',
            ],
            'a ^ whose row strip holds a cell is blocked' => [
                "| a | < |\n| ^ | b |\n",
                '<tr><td colspan="2">a</td></tr><tr><td></td><td>b</td></tr>',
            ],
            'a < beside a cell from an earlier row is blocked' => [
                "| p | q | r | s |\n|---|---|---|---|\n| a | b | c | d |\n| p | ^ | < | e |\n",
                '<tr><td>a</td><td rowspan="2">b</td><td>c</td><td>d</td></tr><tr><td>p</td><td></td><td>e</td></tr>',
            ],
        ];
    }

    #[DataProvider('bodyProvider')]
    public function testBodyRows(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->body($this->converter->convert($source)));
    }

    public function testAHeaderRowKeepsItsColspanPlaceholderInTheHead(): void
    {
        $html = $this->collapse($this->converter->convert("|= 2-col header|<|= header|\n|spans 3 rows|foo|bar|\n"));

        $this->assertStringContainsString(
            '<thead><tr><th scope="col" colspan="2">2-col header</th><th scope="col">header</th></tr></thead>',
            $html,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function astRoundTripProvider(): array
    {
        return [
            'a blocked ^ keeps its row out of the head' => ["|= a | ^ |\n| x | y |\n"],
            'a resolved < stays in the head' => ["|= a | < |\n|= ^ |= b |\n| x | y |\n"],
            'a ^ strip under a two-column cell' => ["|= 2-col header|<|= header|\n|spans 3 rows|foo|bar|\n|^|spans 4 cells|<|\n|^|^|<|\n"],
        ];
    }

    /**
     * The decoder decides header rows with the same walk the parser used.
     */
    #[DataProvider('astRoundTripProvider')]
    public function testTheAstRoundTripRendersTheSameTable(string $source): void
    {
        $codec = new AstCodec();
        $wire = json_decode((string)json_encode($codec->encode($this->converter->parse($source))), true);
        $this->assertIsArray($wire);

        $this->assertSame($this->converter->convert($source), preg_replace('/\n\z/', '', (new HtmlRenderer())->render($codec->decode($wire))));
    }

    public function testEveryThreeByThreeTableOfCellsAndMarkersIsAValidGrid(): void
    {
        $alphabet = ['x', '^', '<'];
        for ($n = 0; $n < 3 ** 9; $n++) {
            $cells = [];
            for ($k = 0, $v = $n; $k < 9; $k++, $v = intdiv($v, 3)) {
                $cells[] = $alphabet[$v % 3];
            }
            $source = '';
            for ($r = 0; $r < 3; $r++) {
                $source .= '| ' . implode(' | ', array_slice($cells, $r * 3, 3)) . " |\n";
            }
            $this->assertSame([], $this->gridErrors($this->converter->convert($source)), $source);
        }
    }

    private function collapse(string $html): string
    {
        return (string)preg_replace('/>\s+</', '><', trim($html));
    }

    private function body(string $html): string
    {
        preg_match('/<tbody>(.*)<\/tbody>/s', $this->collapse($html), $match);

        return $match[1] ?? '';
    }

    /**
     * Place every cell of every row group on the HTML table grid and report a
     * slot taken twice or a row wider than the first.
     *
     * @return list<string>
     */
    private function gridErrors(string $html): array
    {
        $errors = [];
        $width = null;
        preg_match_all('/<(thead|tbody|tfoot)>(.*?)<\/\1>/s', $html, $groups);
        foreach ($groups[2] as $group) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $group, $rows);
            $taken = [];
            $rowWidth = [];
            foreach ($rows[1] as $r => $row) {
                $col = 0;
                preg_match_all('/<(td|th)([^>]*)>/', $row, $cells);
                foreach ($cells[2] as $attrs) {
                    $rowspan = preg_match('/rowspan="(\d+)"/', $attrs, $m) ? (int)$m[1] : 1;
                    $colspan = preg_match('/colspan="(\d+)"/', $attrs, $m) ? (int)$m[1] : 1;
                    while (isset($taken["{$r}:{$col}"])) {
                        $col++;
                    }
                    for ($dr = 0; $dr < $rowspan; $dr++) {
                        for ($dc = 0; $dc < $colspan; $dc++) {
                            $slot = ($r + $dr) . ':' . ($col + $dc);
                            if (isset($taken[$slot])) {
                                $errors[] = "overlap at {$slot}";
                            }
                            $taken[$slot] = true;
                            $rowWidth[$r + $dr] = max($rowWidth[$r + $dr] ?? 0, $col + $dc + 1);
                        }
                    }
                    $col += $colspan;
                }
            }
            ksort($rowWidth);
            foreach ($rowWidth as $w) {
                if ($width === null) {
                    $width = $w;
                } elseif ($w > $width) {
                    $errors[] = "row {$w} wide, table {$width}";
                }
            }
        }

        return $errors;
    }
}
