<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Cells are cut from the row before inline parsing, so a bare `|` in a cell's
 * text always splits the cell. Its escape is unconditional; offering it to the
 * escape search spent a whole-table render and re-parse per probe.
 */
final class APipeInATableCellIsNotAnEscapeCandidateTest extends TestCase
{
    public function testATableWithPipesInItsCellsIsNotSearched(): void
    {
        $renderer = new class extends CarveRenderer {
            public int $bytes = 0;

            protected function canonicalTree(string $source): ?array
            {
                if ($this->treeCacheSource !== $source) {
                    $this->bytes += strlen($source);
                }

                return parent::canonicalTree($source);
            }
        };
        $rows = '';
        for ($i = 0; $i < 80; $i++) {
            $cell = $i % 8 === 0 ? "[a_$i|b_$i|c]" : "plain $i";
            $rows .= "<tr><td><code>c$i</code></td><td>$cell</td></tr>";
        }

        $output = $renderer->render($this->import("<table>$rows</table>"));

        // The search re-parsed the table about 80 times here.
        self::assertLessThan(4, $renderer->bytes / strlen($output));
    }

    public function testThePipeIsEscapedAndNothingElseIs(): void
    {
        $output = (new CarveRenderer())->render($this->import('<table><tr><td>[a_x|b_y]</td><td>c</td></tr></table>'));

        self::assertSame("| [a_x\\|b_y] | c |\n", $output);
    }

    protected function import(string $html): Document
    {
        return (new AstCodec())->decodeImporterTree((new HtmlAstBuilder())->build($html, strlen($html)));
    }
}
