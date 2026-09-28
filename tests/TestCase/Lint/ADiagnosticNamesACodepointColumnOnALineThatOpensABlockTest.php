<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use MarkupCarve\Carve\Lint\SourceLinter;
use MarkupCarve\Carve\Lint\TableColumnLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Three defects in `carve lint`, each one a diagnostic that cannot be acted on
 * (markup-carve/carve-php#2636).
 *
 * `unclosed-container-fence` on a fenced block quote whose closer is right
 * there. The rule finds the closer by reading the container node's own extent,
 * and a fenced quote's extent stopped at its last content line: `deriveContainer
 * Spans()` shrinks a `BlockQuote` to its last child, which is right for the `>`
 * prefix spelling - that form has no closer - and wrong for `::: >`, which does.
 * A div, an admonition and a line block over the same three lines all reported
 * `1->3` while the quote reported `1->2`.
 *
 * `table-column-*` on a line that configures no table. The pass matched any line
 * holding `aligns=` above a pipe row, so a `{...}` run with text beside it - text
 * that attaches to nothing under PART 9 §15 - was read as the table's column
 * metadata.
 *
 * A column in bytes. A `LintWarning`'s `start` and `end` are byte offsets by
 * design; its `column` is the number a `SourceSpan` carries, which PART 12 §4
 * counts in codepoints. Three source-scanning rules passed the byte offset
 * through, so one run reported columns in two units on any line with a
 * non-ASCII character - and inside one file the bidi rule converted while the
 * Markdown-habit rules beside it did not.
 *
 * NOT in scope here, and deliberately: the Markdown-habit rule ids differ from
 * carve-js's migration-check ids for the same delimiter families.
 * `docs/validation.md` names carve-php's ids in its own coverage table and says
 * to treat them as non-portable until they are unified, so renaming them is a
 * spec change rather than a fix. `unattached-block-attribute` is likewise absent
 * here and in carve-js, which makes it a rule to add, not a defect.
 */
class ADiagnosticNamesACodepointColumnOnALineThatOpensABlockTest extends TestCase
{
    /**
     * @return list<array{line: int, column: int, rule: string}>
     */
    private function lint(string $source): array
    {
        $found = [];
        foreach ($this->warningsFor($source) as $warning) {
            $found[] = ['line' => $warning->line, 'column' => $warning->column, 'rule' => $warning->rule];
        }

        return $found;
    }

    /**
     * The three passes these rules live in, in the order `carve lint` runs them.
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function warningsFor(string $source): array
    {
        return [
            ...(new SourceLinter())->lint($source),
            ...(new TableColumnLinter())->lint($source),
            ...(new MarkdownHabitLinter())->lint($source),
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedColonFences(): array
    {
        return [
            'a fenced quote' => ["::: >\nA\n:::\n"],
            // The reported shape: a quote holding a quote, both closed.
            'a fenced quote inside a fenced quote' => ["::: >\nCarol\n::: >\nBob\n:::\n:::\n"],
            'a wider closer pair' => ["::::: >\nA\n:::::\n"],
            // The siblings, which already answered correctly. A fix aimed at
            // the quote must not move them.
            'a bare div' => [":::\nA\n:::\n"],
            'an admonition' => ["::: note\nA\n:::\n"],
            'a line block' => ["::: |\nA\n:::\n"],
        ];
    }

    #[DataProvider('closedColonFences')]
    public function testAClosedFenceIsNotReportedUnclosed(string $source): void
    {
        $this->assertSame([], $this->lint($source));
    }

    /**
     * The half that must still report, so the rule is not simply switched off.
     */
    public function testAnUnclosedFencedQuoteIsStillReported(): void
    {
        $this->assertSame(
            [['line' => 1, 'column' => 1, 'rule' => 'unclosed-container-fence']],
            $this->lint("::: >\nA\n"),
        );
    }

    /**
     * A fenced quote's extent covers its closer, as every other colon-fence
     * container's does. The `>` prefix spelling has no closer and keeps ending
     * at its last child.
     *
     * @return array<string, array{string, int}>
     */
    public static function quoteExtents(): array
    {
        return [
            'the fenced spelling reaches its closer' => ["::: >\nA\n:::\n", 3],
            'an unclosed fence ends at its content' => ["::: >\nA\n", 2],
            'the prefix spelling ends at its last child' => ["> A\n> B\n", 2],
        ];
    }

    #[DataProvider('quoteExtents')]
    public function testAQuoteReachesTheEndLineItsSpellingImplies(string $source, int $endLine): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $quote = $converter->parse($source)->getChildren()[0];
        $pos = $quote->getPos();
        $this->assertNotNull($pos);
        $this->assertSame($endLine, $pos->endLine);
    }

    /**
     * @return array<string, array{string, list<array{line: int, column: int, rule: string}>}>
     */
    public static function attributeLines(): array
    {
        return [
            // A real block-attribute line still reports, at the codepoint
            // column of the key.
            'a block attribute line' => [
                "{aligns=\"left\"}\n| a | b |\n",
                [['line' => 1, 'column' => 2, 'rule' => 'table-column-arity']],
            ],
            // Text beside the braces: the run attaches to nothing, so the
            // table is unconfigured and there is nothing to report.
            'text before the braces' => ["x {aligns=\"left\"}\n| a | b |\n", []],
            'the reported non-ASCII spelling' => ["é😀 {aligns=\"left\"}\n| a | b |\n", []],
            'text after the braces' => ["{aligns=\"left\"} x\n| a | b |\n", []],
            'the overlap rule answers the same' => ["x {aligns=\"left\"}\n|=< a | b |\n", []],
            'the width rule answers the same' => ["x {widths=\"80,80\"}\n| a | b |\n", []],
        ];
    }

    /**
     * @param string $source
     * @param list<array{line: int, column: int, rule: string}> $expected
     */
    #[DataProvider('attributeLines')]
    public function testOnlyAnAttributeLineConfiguresATable(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->lint($source));
    }

    /**
     * The columns below are codepoint columns, counted by hand off the source.
     * A byte column reads 9 and 15 for the first row, and a UTF-16 column reads
     * 6 and 12; the AST's own `startColumn` for the same line counts
     * codepoints, which is the unit a consumer can line a diagnostic up with.
     *
     * @return array<string, array{string, list<array{line: int, column: int, rule: string}>}>
     */
    public static function nonAsciiPrefixes(): array
    {
        return [
            'an astral character ahead of the runs' => [
                "😀 é **b** ~~d~~\n",
                [
                    ['line' => 1, 'column' => 5, 'rule' => 'markdown-strong-asterisks'],
                    ['line' => 1, 'column' => 11, 'rule' => 'markdown-strikethrough'],
                ],
            ],
            'a two-byte character ahead of the run' => [
                "é **b**\n",
                [['line' => 1, 'column' => 3, 'rule' => 'markdown-strong-asterisks']],
            ],
            // ASCII already answered correctly, which is why the defect stayed
            // invisible: every column in the fixtures was also a byte column.
            'pure ASCII is unchanged' => [
                "a **b**\n",
                [['line' => 1, 'column' => 3, 'rule' => 'markdown-strong-asterisks']],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<array{line: int, column: int, rule: string}> $expected
     */
    #[DataProvider('nonAsciiPrefixes')]
    public function testAColumnCountsCodepoints(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->lint($source));
    }

    /**
     * `start` and `end` stay BYTE offsets, which is what a PHP caller slices the
     * source with. The fix moved the column and must not have moved these.
     */
    public function testTheOffsetsStayInBytes(): void
    {
        $source = "😀 é **b**\n";
        $warnings = $this->warningsFor($source);
        $this->assertCount(1, $warnings);
        $this->assertSame('**b**', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }
}
