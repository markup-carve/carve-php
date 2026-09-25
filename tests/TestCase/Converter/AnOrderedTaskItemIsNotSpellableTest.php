<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * cmark-gfm's task-list extension reaches an ordered item, so `1. [x] done`
 * carries a checkbox for the reader the importers answer to
 * (markup-carve/carve#2187). Carve has no ordered task item to write it as:
 * `task_marker` is reachable from `unordered_item` alone (PART 3,
 * `resources/spec/03-blocks-core.ebnf`).
 *
 * So the characters cmark-gfm read survive as text - `<ol><li>[x] done</li></ol>`
 * rather than an invented bullet list or a silently shorter item - and the
 * checkbox itself is gone. A loss the target language forces is still a loss to
 * report, so the fidelity report carries a `structure-unspellable` row naming
 * the source line (carve-php#2366). carve-rs#1891 landed the same reading.
 *
 * The bracket text was already right here; the row is what was missing.
 */
final class AnOrderedTaskItemIsNotSpellableTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function orderedProvider(): array
    {
        return [
            'a checked ordered item' => [
                "1. [x] done\n",
                "1. [x] done\n",
                '<ol><li>[x] done</li></ol>',
            ],
            'an unchecked ordered item' => [
                "1. [ ] todo\n",
                "1. [ ] todo\n",
                '<ol><li>[ ] todo</li></ol>',
            ],
            'a capital state' => [
                "1. [X] done\n",
                "1. [X] done\n",
                '<ol><li>[X] done</li></ol>',
            ],
            'a paren delimiter' => [
                "1) [x] done\n",
                "1) [x] done\n",
                '<ol><li>[x] done</li></ol>',
            ],
            'a start other than one' => [
                "3. [x] done\n",
                "3. [x] done\n",
                '<ol start="3"><li>[x] done</li></ol>',
            ],
        ];
    }

    #[DataProvider('orderedProvider')]
    public function testTheMarkerSurvivesAsText(string $markdown, string $carve, string $html): void
    {
        $converter = new MarkdownToCarve();

        $this->assertSame($carve, $converter->convert($markdown));
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * The source positions that owe a row, and what each one's row says.
     *
     * @return array<string, array{string, array<int, string>}>
     */
    public static function reportProvider(): array
    {
        return [
            'one ordered task item' => ["1. [x] done\n", ['line:1']],
            'two of them' => ["1. [x] a\n2. [ ] b\n", ['line:1', 'line:2']],
            // cmark-gfm reads an empty box when whitespace follows the pair,
            // even if the rest of the line is blank.
            'empty task with trailing space' => ["1. [x] \n", ['line:1']],
            'empty task with trailing tab' => ["1. [x]\t\n", ['line:1']],
            'four columns of marker padding' => ["1.    [x] done\n", ['line:1']],
            'a tab within four padding columns' => ["1.\t[x] done\n", ['line:1']],
            'a CRLF empty task' => ["1. [x] \r\n", ['line:1']],
            // An ordered list a bullet item holds: the extension reaches it, the
            // marker still sits on a line of its own, and Carve still cannot
            // spell the box.
            'an ordered sublist on its own line' => ["- a\n\n   1. [x] done\n", ['line:3']],
            // cmark-gfm reads no box behind a quote marker, so the two readers
            // agree and nothing is lost.
            'a quoted ordered item owes no row' => ["> 1. [x] done\n", []],
            'an ordered item a list item holds on one line owes no row' => ["- 1. [x] done\n", []],
            // A bullet keeps its box, so nothing is unspellable.
            'a bullet task item owes no row' => ["- [x] done\n", []],
            // Not a task line at all in either reader.
            'a plain ordered item owes no row' => ["1. done\n", []],
            'a bracket pair without separator owes no row' => ["1. [x]\n", []],
            'five columns of marker padding start code' => ["1.     [x] done\n", []],
            'a tab and spaces exceed four padding columns' => ["1.\t   [x] done\n", []],
            'a two-character state owes no row' => ["1. [xx] done\n", []],
            // Inside a code block the line is content, not an item.
            'a fenced line owes no row' => ["~~~\n1. [x] done\n~~~\n", []],
            'an indented code line owes no row' => ["text\n\n    1. [x] done\n", []],
        ];
    }

    /**
     * @param string $markdown
     * @param array<int, string> $paths
     */
    #[DataProvider('reportProvider')]
    public function testTheReportNamesTheUnspellableItem(string $markdown, array $paths): void
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown)->report();
        $unspellable = array_values(array_filter(
            $report['diagnostics'],
            static fn (array $row): bool => $row['code'] === 'structure-unspellable',
        ));

        $this->assertSame($paths, array_column($unspellable, 'path'));
        foreach ($unspellable as $row) {
            $this->assertSame('warning', $row['severity']);
            $this->assertSame('dropped', $row['fidelity']);
            $this->assertSame('exact', $row['confidence']);
            $this->assertStringContainsString('ordered task item', $row['message']);
        }
    }

    /**
     * The blanket row the Markdown importer owes for having no construct-level
     * evidence stays where it was: the new row is additional, not a replacement.
     */
    public function testTheUnverifiedRowStaysFirst(): void
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport("1. [x] done\n")->report();

        $this->assertSame(
            ['fidelity-unverified', 'structure-unspellable'],
            array_column($report['diagnostics'], 'code'),
        );
    }

    /**
     * A document with no unspellable item reports exactly what it did before.
     */
    public function testAReportWithoutOneIsUnchanged(): void
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport("**strong**\n")->report();

        $this->assertSame(['fidelity-unverified'], array_column($report['diagnostics'], 'code'));
    }

    /**
     * The rows belong to the document just converted, not to the one before it.
     */
    public function testTheRowsDoNotSurviveTheNextConversion(): void
    {
        $converter = new MarkdownToCarve();
        $converter->convertWithFidelityReport("1. [x] done\n");
        $report = $converter->convertWithFidelityReport("- [x] done\n")->report();

        $this->assertSame(['fidelity-unverified'], array_column($report['diagnostics'], 'code'));
    }

    /**
     * The text the importer keeps re-reads as itself, so the import is already
     * formatted and a second pass invents no checkbox.
     */
    public function testTheKeptTextRoundTrips(): void
    {
        $carve = (new MarkdownToCarve())->convert("1. [x] done\n");

        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }

    private function render(string $markdown): string
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/\s+aria-label="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '', '><', ' '],
            $html,
        ));
    }
}
