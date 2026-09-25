<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An ordered task item's checkbox was dropped on HTML import and the rows that
 * appeared named the ATTRIBUTES rather than the loss, so a consumer filtering on
 * the code was told an attribute problem where a construct was lost
 * (carve-php#2381).
 *
 * `task_marker` in `resources/spec/03-blocks-core.ebnf` hangs off
 * `unordered_item` alone, so no Carve source carries a box behind an ordered
 * marker and no engine can write the faithful answer. The importer keeps the
 * characters the box was read from and names what it could not spell, which is
 * what markup-carve/carve-rs#1890 ruled and carve-rs#1904 and carve-js#2059
 * pinned - the row's wording included.
 *
 * Only a WRITER loses this (PART 12 section 16), so the AST exit keeps `checked`
 * on the ordered item and reports nothing.
 *
 * The ROW is the Markdown entry point's message, which the import contract pins
 * for both (carve-js#2062). This side used to add "a Carve task marker is
 * spelled behind a bullet only", which is `unordered_item` restated inside a
 * diagnostic: one loss with one cause now reads the same whichever importer ran.
 */
final class AnOrderedHtmlTaskItemDeclaresItsLostBoxTest extends TestCase
{
    /**
     * @var string
     */
    private const ROW = 'An ordered task item is not spellable as a Carve task item; '
        . 'the checkbox marker was kept as text';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function orderedProvider(): array
    {
        return [
            'the ticket\'s input' => [
                '<ol><li><input type="checkbox" checked disabled> done</li></ol>',
                "1. [x] done\n",
                '<ol><li>[x] done</li></ol>',
            ],
            'an unchecked box' => [
                '<ol><li><input type="checkbox" disabled> todo</li></ol>',
                "1. [ ] todo\n",
                '<ol><li>[ ] todo</li></ol>',
            ],
            'a start other than one' => [
                '<ol start="3"><li><input type="checkbox" checked disabled> done</li></ol>',
                "3. [x] done\n",
                '<ol start="3"><li>[x] done</li></ol>',
            ],
            // A `data-task-state` character reaches the brackets, because that is
            // the marker a bullet would have carried.
            'a Carve-only state' => [
                '<ol><li data-task-state="-"><input type="checkbox" disabled> mid</li></ol>',
                "1. [-] mid\n",
                '<ol><li>[-] mid</li></ol>',
            ],
            'a label around the box' => [
                '<ol><li><label><input type="checkbox" checked disabled> done</label></li></ol>',
                "1. [x] done\n",
                '<ol><li>[x] done</li></ol>',
            ],
            // The box was the whole item's content. It used to write the bare
            // continuation marker `1. +`, nothing being left behind it.
            'an item whose only content was the box' => [
                '<ol><li><input type="checkbox" checked disabled></li></ol>',
                "1. [x]\n",
                '<ol><li>[x]</li></ol>',
            ],
            'an ordered task list nested in an ordered item' => [
                '<ol><li>a<ol><li><input type="checkbox" checked disabled> inner</li></ol></li></ol>',
                "1. a\n   1. [x] inner\n",
                '<ol><li>a <ol><li>[x] inner</li></ol></li></ol>',
            ],
        ];
    }

    #[DataProvider('orderedProvider')]
    public function testTheBracketTextSurvivesAndTheLossIsNamed(string $html, string $carve, string $rendered): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame($rendered, $this->render($result->value));
        $this->assertSame([self::ROW], self::messagesWithCode($result->diagnostics, 'structure-unspellable'));
    }

    /**
     * The row stands at the `<input>`'s own path, and it stands INSTEAD of the
     * attribute rows the box's own state used to spend.
     */
    public function testTheRowReplacesTheAttributeRows(): void
    {
        $result = (new HtmlToCarve())
            ->convertWithReport('<ol><li><input type="checkbox" checked disabled> done</li></ol>');

        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('structure-unspellable', $result->diagnostics[0]->code);
        $this->assertSame('warning', $result->diagnostics[0]->severity);
        $this->assertSame('/ol[1]/li[1]/input[1]', $result->diagnostics[0]->path);
    }

    /**
     * Every OTHER attribute on that same input keeps its ordinary treatment, so
     * the absorption is scoped to what the brackets carry rather than silencing
     * the element.
     */
    public function testAnUnrelatedAttributeOnTheBoxStillReportsItsLoss(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<ol><li><input type="checkbox" checked disabled name="n" value="v"> done</li></ol>',
        );

        $this->assertSame(
            ['structure-unspellable', 'attribute-dropped', 'attribute-dropped'],
            array_map(static fn (HtmlImportDiagnostic $row): string => $row->code, $result->diagnostics),
        );
        $this->assertSame(
            [
                'Dropped unsupported attribute name on <input>',
                'Dropped unsupported attribute value on <input>',
            ],
            self::messagesWithCode($result->diagnostics, 'attribute-dropped'),
        );
    }

    /**
     * A BULLET task item keeps its box and reports nothing. Without this, an
     * importer that stopped reading checkboxes at all would pass the rest.
     */
    public function testABulletTaskItemKeepsItsBoxAndReportsNothing(): void
    {
        $result = (new HtmlToCarve())
            ->convertWithReport('<ul><li><input type="checkbox" checked disabled> done</li></ul>');

        $this->assertSame("- [x] done\n", $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    /**
     * A bullet task list nested INSIDE an ordered item keeps its boxes while only
     * the outer item is reported: the rule reads the item's own list, not the
     * nesting.
     */
    public function testOnlyTheOrderedItemIsReported(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<ol><li><input type="checkbox" checked disabled> outer'
                . '<ul><li><input type="checkbox" disabled> inner</li></ul></li></ol>',
        );

        $this->assertSame("1. [x] outer\n   - [ ] inner\n", $result->value);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('/ol[1]/li[1]/input[1]', $result->diagnostics[0]->path);
    }

    /**
     * And the mirror: a bullet item holding an ordered task list keeps its own box
     * while the inner ordered item is the one reported.
     */
    public function testTheInnerOrderedItemIsTheReportedOne(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<ul><li><input type="checkbox" disabled> outer'
                . '<ol><li><input type="checkbox" checked disabled> inner</li></ol></li></ul>',
        );

        $this->assertSame("- [ ] outer\n  1. [x] inner\n", $result->value);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('/ul[1]/li[1]/ol[3]/li[1]/input[1]', $result->diagnostics[0]->path);
    }

    /**
     * THE TWO EXITS, pinned against each other. The AST holds the box on the
     * ordered item, so nothing is lost there and nothing is reported - while the
     * source exit for the same tree writes the bracket text and reports.
     */
    public function testTheAstExitKeepsTheBoxAndReportsNothing(): void
    {
        $html = '<ol><li data-task-state="-"><input type="checkbox" disabled> mid</li></ol>';
        $ast = (new HtmlToCarve())->convertToAstWithReport($html);

        $item = $ast->value['children'][0]['items'][0];
        $this->assertFalse($item['checked']);
        $this->assertSame('-', $item['taskState']);
        $this->assertSame([], $ast->diagnostics);

        $source = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame("1. [-] mid\n", $source->value);
        $this->assertSame([self::ROW], self::messagesWithCode($source->diagnostics, 'structure-unspellable'));
    }

    /**
     * A checkbox that is not the item's head is no task item in either reader, so
     * it keeps the element and attribute rows it already owed.
     */
    public function testACheckboxPastTheItemsHeadIsUnchanged(): void
    {
        $result = (new HtmlToCarve())
            ->convertWithReport('<ol><li>before <input type="checkbox" checked disabled> after</li></ol>');

        $this->assertSame([], self::messagesWithCode($result->diagnostics, 'structure-unspellable'));
        $this->assertContains(
            'element-dropped',
            array_map(static fn (HtmlImportDiagnostic $row): string => $row->code, $result->diagnostics),
        );
    }

    /**
     * THE TWO ENTRY POINTS, pinned against each other. That is the ruling itself
     * (carve-js#2062): one loss with one cause, so a consumer filtering on the
     * message does not have to know which importer ran. The literal stays spelled
     * out here rather than read from the importer, because a test that reads the
     * value it is checking cannot see a wrong string.
     */
    public function testBothEntryPointsSayTheSameThing(): void
    {
        $fromHtml = (new HtmlToCarve())
            ->convertWithReport('<ol><li><input type="checkbox" checked> done</li></ol>');
        $this->assertSame([self::ROW], self::messagesWithCode($fromHtml->diagnostics, 'structure-unspellable'));

        $fromMarkdown = (new MarkdownToCarve())->convertWithFidelityReport("1. [x] done\n");
        $rows = array_values(array_filter(
            $fromMarkdown->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->code === 'structure-unspellable',
        ));
        $this->assertSame([self::ROW], array_map(static fn (MigrationDiagnostic $row): string => $row->message, $rows));
        // Everything but `path` matches too: an HTML importer locates the
        // `<input>` it read and a Markdown importer names a source line.
        foreach ($rows as $row) {
            $this->assertSame('warning', $row->severity);
            $this->assertSame('dropped', $row->fidelity);
            $this->assertSame('exact', $row->confidence);
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     * @param string $code
     *
     * @return list<string>
     */
    private static function messagesWithCode(array $diagnostics, string $code): array
    {
        return array_values(array_map(
            static fn (HtmlImportDiagnostic $row): string => $row->message,
            array_filter($diagnostics, static fn (HtmlImportDiagnostic $row): bool => $row->code === $code),
        ));
    }

    private function render(string $carve): string
    {
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/\s+aria-label="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '', '><', ' '],
            $html,
        ));
    }
}
