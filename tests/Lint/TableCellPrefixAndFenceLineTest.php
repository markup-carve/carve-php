<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6587: a brace block in the last cell's prefix is not a row attribute as cell content.
 * carve#6588: fence-closer-indented names the opener's line.
 */
final class TableCellPrefixAndFenceLineTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function tableCases(): array
    {
        return [
            'alignment-only prefix block in the only cell' => ["|{?v} |\n", []],
            'alignment-only prefix block in the last cell' => ["| a |{?v} |\n", []],
            'horizontal alignment prefix block' => ["| a |{>} |\n", []],
            'alignment and attributes in the prefix block' => ["| a |{> .x} |\n", []],
            'attribute block as cell content' => ["| a | {.x} |\n", ['/children/0/children/0/children/0']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $paths
     *
     * @return void
     */
    #[DataProvider('tableCases')]
    public function testRowAttributeAsCell(string $source, array $paths): void
    {
        $found = [];
        foreach (Carve::lint($source) as $finding) {
            if ($finding->code === 'table-row-attribute-as-cell') {
                $found[] = $finding->path;
            }
        }
        $this->assertSame($paths, $found);
    }

    public function testFenceCloserNamesTheOpenerLine(): void
    {
        $messages = [];
        // CC-2041: past the footnote's content column the closer is still reported (carve#6585 A2).
        foreach (Carve::lint("x[^1]\n\n[^1]: ```\n  code\n        ```\n\nafter\n") as $finding) {
            if ($finding->code === 'fence-closer-indented') {
                $messages[] = $finding->message;
            }
        }
        $this->assertSame([
            'This line closes the block opened on line 3 although it is indented differently. '
            . 'If it is content, make the outer fence longer.',
        ], $messages);
    }
}
