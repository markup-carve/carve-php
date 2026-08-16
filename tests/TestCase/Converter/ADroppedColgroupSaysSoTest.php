<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A `<colgroup>` inside a table is dropped, and now says it is.
 *
 * Carve has no column model - a table's columns are only the cells its rows
 * carry - and whether it should get one is a language question
 * (`markup-carve/carve#1092`), not this importer's to answer. What the importer
 * owes meanwhile is a true name for the loss. It had a false one: the table
 * walk reads rows, so the element and everything under it left the document,
 * while the report said `element-unwrapped` at `info` and added a row under
 * each `<col>` promising Carve span metadata that is never written.
 *
 * The wording is verbatim from `markup-carve/carve-rs#1006` and
 * `markup-carve/carve-js#1102`, so the three engines report the drop in the
 * same words.
 *
 * Only `<colgroup>` is scanned for, matching the sibling engines. The reason
 * they give is that an HTML5 parser answers a `col` start tag in "in table"
 * insertion mode by inserting an implied `<colgroup>` first, so a bare `<col>`
 * is never a direct child of a `<table>`. That reason does NOT hold for this
 * engine: libxml's parser has no insertion modes and keeps the `<col>` exactly
 * where the markup put it. `theBareColShapeDivergesFromTheSiblingEngines`
 * below pins what actually arrives here rather than assuming the sibling's
 * premise, so the difference is a measured fact in the suite instead of a
 * comment nobody can check.
 */
class ADroppedColgroupSaysSoTest extends TestCase
{
    /**
     * @var string
     */
    protected const MESSAGE = 'Dropped <colgroup>: Carve has no column model, '
        . "and a table's columns are only the cells its rows carry";

    /**
     * @return list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic>
     */
    protected function diagnostics(string $html, string $mode = 'safe'): array
    {
        return (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->diagnostics;
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     *
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(array $diagnostics): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [
                'code' => $diagnostic->code,
                'message' => $diagnostic->message,
                'severity' => $diagnostic->severity,
                'path' => $diagnostic->path,
            ],
            $diagnostics,
        );
    }

    /**
     * @return list<string>
     */
    protected function droppedPaths(string $html): array
    {
        $paths = [];
        foreach ($this->diagnostics($html) as $diagnostic) {
            if ($diagnostic->message !== self::MESSAGE) {
                continue;
            }
            $paths[] = $diagnostic->path;
        }

        return $paths;
    }

    public function testNamesTheElementItsSeverityAndItsOwnPath(): void
    {
        $html = '<table><colgroup><col span="2"></colgroup><tr><td>a</td><td>b</td></tr></table>';

        $this->assertSame(
            [
                [
                    'code' => 'element-dropped',
                    'message' => self::MESSAGE,
                    'severity' => 'warning',
                    'path' => '/table[1]/colgroup[1]',
                ],
            ],
            $this->rows($this->diagnostics($html)),
        );
    }

    /**
     * The `<col>` inside carries an attribute the importer has no mapping for,
     * and it used to get two rows of its own. Neither survives: the element it
     * hangs off is gone, so an attribute report on it would describe a loss
     * inside a loss and point at a path the reader cannot act on.
     */
    public function testSaysNothingAboutTheColumnsInsideIt(): void
    {
        $html = '<table><colgroup><col span="2" style="width:4em"><col></colgroup>'
            . '<tr><td>a</td><td>b</td></tr></table>';

        $this->assertSame(['element-dropped'], array_column($this->rows($this->diagnostics($html)), 'code'));
    }

    /**
     * The element's own attributes go with it too, the way an active element's
     * do - a `<colgroup onclick="...">` is one drop, not a drop plus a handler
     * report hanging off a path that no longer names anything.
     */
    public function testTheElementsOwnAttributesGoWithIt(): void
    {
        $html = '<table><colgroup span="3" onclick="evil()" style="width:4em"><col></colgroup>'
            . '<tr><td>a</td></tr></table>';

        $this->assertSame(['element-dropped'], array_column($this->rows($this->diagnostics($html)), 'code'));
    }

    /**
     * The report is the only thing that changes: a column description Carve
     * cannot hold is not a reason to lose the cells that it can.
     */
    public function testKeepsTheRestOfTheTable(): void
    {
        $html = '<table><colgroup><col><col></colgroup><tr><td>a</td><td>b</td></tr></table>';

        $this->assertSame("| a | b |\n", (new HtmlToCarve())->convert($html));
    }

    /**
     * The path is the assertion that matters here. Collapsing every diagnostic
     * onto the table's own path still passes a test that reads only codes and
     * messages, and two colgroups reported under one path is a report that
     * cannot say which element went.
     */
    public function testGivesEachOfTwoColgroupsItsOwnPathNotTheTables(): void
    {
        $html = '<table><colgroup></colgroup><colgroup span="3"></colgroup><tr><td>a</td></tr></table>';

        $paths = $this->droppedPaths($html);
        $this->assertSame(['/table[1]/colgroup[1]', '/table[1]/colgroup[2]'], $paths);
        $this->assertCount(2, array_unique($paths));
        $this->assertNotContains('/table[1]', $paths);
    }

    /**
     * `<caption>` is child one, so the `<colgroup>` is child two. The importer
     * numbers a child by its position among the parent's element children,
     * which is what the second-caption report already does, and a path built
     * from a per-name count would say `colgroup[1]` for an element that is not
     * first.
     */
    public function testCountsTheSiblingsBeforeIt(): void
    {
        $html = '<table><caption>C</caption><colgroup><col></colgroup>'
            . '<thead><tr><th>h</th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>';

        $this->assertSame(['/table[1]/colgroup[2]'], $this->droppedPaths($html));
    }

    public function testReportsItUnderTheTablesOwnPlaceInTheDocument(): void
    {
        $html = '<blockquote><table><colgroup><col></colgroup><tr><td>a</td></tr></table></blockquote>';

        $this->assertSame(['/blockquote[1]/table[1]/colgroup[1]'], $this->droppedPaths($html));
    }

    /**
     * The element has no representation anywhere, so no mode can keep it -
     * including `roundtrip`, where an unsupported element is otherwise
     * preserved rather than lost.
     */
    public function testSaysItInEveryMode(): void
    {
        $html = '<table><colgroup><col></colgroup><tr><td>a</td></tr></table>';

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $this->assertSame(['element-dropped'], array_column($this->rows($this->diagnostics($html, $mode)), 'code'), $mode);
        }

        $trusted = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport($html)->diagnostics;
        $this->assertSame(['element-dropped'], array_column($this->rows($trusted), 'code'));
    }

    /**
     * CONTROL. Without this, an arm that fired on every table would still pass
     * every assertion above.
     */
    public function testATableWithoutOneReportsNothing(): void
    {
        $this->assertSame([], $this->rows($this->diagnostics('<table><tr><td>a</td><td>b</td></tr></table>')));
    }

    /**
     * A `<colgroup>` that is not a table's child is a different case and keeps
     * the answer it had. libxml keeps such an element where the markup put it,
     * and the converter walks straight through it, so its content DOES reach
     * the output - it is unwrapped, which is what the report already said. The
     * drop is a property of the table walk, so the report asks the same
     * question the walk answers to.
     */
    public function testAColgroupOutsideATableIsStillUnwrappedNotDropped(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<colgroup><p>kept</p></colgroup>');

        $this->assertSame("kept\n", $result->value);
        $this->assertSame(['element-unwrapped'], array_column($this->rows($result->diagnostics), 'code'));
    }

    /**
     * DIVERGENCE, pinned rather than papered over.
     *
     * `markup-carve/carve-rs#1006` and `markup-carve/carve-js#1102` scan for
     * `<colgroup>` alone because their parsers run the HTML5 "in table"
     * insertion mode, which answers a `col` start tag by inserting an implied
     * `<colgroup>` first - so on those engines this input reports the drop, and
     * a `col` arm would be a check that cannot fail (`markup-carve/carve#755`).
     *
     * This engine parses with libxml, which has no insertion modes. The `<col>`
     * arrives as a DIRECT child of the `<table>`, no wrapper is implied, and
     * the same input therefore reports `element-unwrapped` at `info` under the
     * `<col>`'s own path instead of `element-dropped` at `warning` under a
     * `<colgroup>`'s. That is a real cross-engine difference on legal HTML, and
     * it is a maintainer's call whether the answer is a `col` arm here or a
     * different parser; scanning for `<col>` on the quiet would have hidden the
     * question. This test states what arrives so the answer can be checked
     * against it, and so the day the parser starts implying the wrapper is the
     * day this test says so.
     */
    public function testTheBareColShapeDivergesFromTheSiblingEngines(): void
    {
        $rows = $this->rows($this->diagnostics('<table><col span="2"><col><tr><td>a</td><td>b</td></tr></table>'));

        $this->assertSame([], $this->droppedPaths('<table><col span="2"><col><tr><td>a</td><td>b</td></tr></table>'));
        $this->assertSame(
            ['/table[1]/col[1]', '/table[1]/col[1]', '/table[1]/col[2]'],
            array_column($rows, 'path'),
        );
        $this->assertSame(
            ['attribute-dropped', 'element-unwrapped', 'element-unwrapped'],
            array_column($rows, 'code'),
        );
    }

    /**
     * The explicit wrapper and the bare `<col>` after it are two separate
     * shapes here, and only the first is the element this change reports.
     */
    public function testAnExplicitWrapperDoesNotAdoptTheColumnsAfterIt(): void
    {
        $rows = $this->rows($this->diagnostics('<table><colgroup><col></colgroup><col><tr><td>a</td></tr></table>'));

        $this->assertSame(['element-dropped', 'element-unwrapped'], array_column($rows, 'code'));
        $this->assertSame(
            ['/table[1]/colgroup[1]', '/table[1]/col[2]'],
            array_column($rows, 'path'),
        );
    }

    /**
     * The report has to validate against the code set the spec publishes.
     * `element-dropped` was already in the enum, so the arm is not what could
     * have broken the contract - but a diagnostic nobody walks through the
     * schema is a diagnostic whose `code` and `severity` are unchecked against
     * it.
     */
    public function testTheReportValidatesAgainstThePublishedSchema(): void
    {
        $schemaJson = file_get_contents(dirname(__DIR__, 2) . '/spec/resources/html-import-schema.json');
        $this->assertNotFalse($schemaJson);
        $schema = json_decode($schemaJson, true, flags: JSON_THROW_ON_ERROR);
        $codes = $schema['properties']['diagnostics']['items']['properties']['code']['enum'];
        $severities = $schema['properties']['diagnostics']['items']['properties']['severity']['enum'];

        $report = (new HtmlToCarve())
            ->convertWithReport('<table><colgroup><col></colgroup><tr><td>a</td></tr></table>')
            ->report();

        $this->assertContains($report['mode'], $schema['properties']['mode']['enum']);
        $this->assertContains($report['adapter'], $schema['properties']['adapter']['enum']);
        $this->assertSame(['element-dropped'], array_column($report['diagnostics'], 'code'));
        foreach ($report['diagnostics'] as $diagnostic) {
            $this->assertContains($diagnostic['code'], $codes);
            $this->assertContains($diagnostic['severity'], $severities);
            $this->assertIsString($diagnostic['message']);
        }
    }
}
