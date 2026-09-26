<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The list-table import option and the Markdown target's list-table writing
 * (markup-carve/carve#2391, markup-carve/carve#2392). The byte expectations
 * are the ones carve-js and carve-rs produce for the same inputs.
 */
class ATableWhoseCellsHoldBlocksImportsAsAListTableTest extends TestCase
{
    /**
     * @var string
     */
    private const STEPS = "<table>\n<caption>Steps</caption>\n<tr><th>Step</th><th>Detail</th></tr>\n"
        . "<tr><th>1</th><td><p>Install.</p><pre><code>npm i</code></pre></td></tr>\n"
        . "<tr><td colspan=\"2\">^</td></tr>\n</table>";

    /**
     * @var string
     */
    private const STEPS_CARVE = "{header-rows=1}\n::: list-table \"Steps\"\n- - Step\n  - Detail\n"
        . "- -{header} 1\n\n  - Install.\n\n    ```\n    npm i\n    ```\n- - \\^\n  - <\n:::\n";

    public function testTheContractExampleImportsAsAListTable(): void
    {
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport(self::STEPS);

        $this->assertSame(self::STEPS_CARVE, $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    public function testHeaderColumnsAreCountedOverTheGridAndABlankRowStays(): void
    {
        $html = '<table><tr><th>H</th><th>I</th></tr>'
            . '<tr><th rowspan="2">R</th><td><ul><li>a</li></ul></td></tr>'
            . '<tr><td>b</td></tr><tr><th></th><td></td></tr></table>';

        $this->assertSame(
            "{header-rows=1 header-cols=1}\n::: list-table\n- - H\n  - I\n- - R\n  - - a\n"
            . "- - ^\n  - b\n- - +\n  - +\n:::\n",
            (new HtmlToCarve(listTableForBlockCells: true))->convert($html),
        );
    }

    public function testARowReportsDroppingItsOwnAttributes(): void
    {
        $html = '<table id="t"><tr id="r"><td class="k"><p>a</p><p>b</p></td></tr></table>';
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);

        $this->assertSame("{#t}\n::: list-table\n- -{.k} a\n\n    b\n:::\n", $result->value);
        $this->assertSame(
            [['attribute-dropped', 'info', '/table[1]/tr[1]']],
            array_map(static fn ($d): array => [$d->code, $d->severity, $d->path], $result->diagnostics),
        );
    }

    public function testARowWithNoCellsIsDroppedAndReported(): void
    {
        $html = '<table><tr></tr><tr><th>H</th></tr><tr><td><p>a</p><p>b</p></td></tr></table>';
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);

        $this->assertSame("{header-rows=1}\n::: list-table\n- - H\n- - a\n\n    b\n:::\n", $result->value);
        $this->assertSame(
            [['structure-unspellable', '/table[1]/tr[1]']],
            array_map(static fn ($d): array => [$d->code, $d->path], $result->diagnostics),
        );
    }

    public function testTheAstExitPublishesTheAdmonition(): void
    {
        $ast = (new HtmlToCarve(listTableForBlockCells: true))->convertToAstWithReport(self::STEPS)->value;

        $this->assertSame('admonition', $ast['children'][0]['type']);
        $this->assertSame('list-table', $ast['children'][0]['kind']);
    }

    public function testTheMarkdownTargetWritesAListTableAsAPipeTable(): void
    {
        $this->assertSame(
            "| Step | Detail |\n| --- | --- |\n| 1 | Install. npm i |\n| ^ |  |\n\nSteps\n",
            $this->markdown(self::STEPS_CARVE),
        );
        $this->assertSame(
            "| a | b |\n| ---: | --- |\n",
            $this->markdown("{aligns=\"right\" header-cols=1}\n::: list-table\n- - a\n  -{header} b\n:::\n"),
        );
    }

    public function testEveryListInARowGivesCellsAndTheLabelIsKept(): void
    {
        $this->assertSame(
            "**Lbl**\n\n|  |  |  |  |\n| --- | --- | --- | --- |\n| a | b | c | d |\n",
            $this->markdown("::: list-table [Lbl]\n- - a\n  - b\n\n  1. c\n  2. d\n:::\n"),
        );
    }

    public function testABodyThatIsNotAGridKeepsTheOldWriting(): void
    {
        $this->assertSame("**T**\n\n- one\n- two\n", $this->markdown("::: list-table \"T\"\n- one\n- two\n:::\n"));
    }

    private function markdown(string $source): string
    {
        return (new MarkdownRenderer())->render((new CarveConverter())->parse($source));
    }
}
