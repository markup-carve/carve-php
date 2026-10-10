<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whitespace a decoded character reference puts at the head of a block does not
 * reach the document, and the report names it (carve-php#3050).
 *
 * The drop itself is markup-carve/carve#2595: the character goes rather than
 * being substituted, because the old `\ ` substitution reads back as U+00A0.
 */
class ADecodedLeadingWhitespaceIsDroppedAndReportedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function drops(): array
    {
        return [
            'the reported shape' => ['&#32;leading outside a table', 'leading outside a table'],
            'a tab' => ['&#9;tab leading', 'tab leading'],
            'a run of references' => ['&#32;&#32;&#32;&#32;four spaces', 'four spaces'],
            // The drop uncovers a marker at column 0, so the line has to stay
            // the paragraph it was.
            'an uncovered bullet' => ['&#32;- item', '\- item'],
            'an uncovered heading' => ['&#32;# head', '\# head'],
            'an uncovered ordered marker' => ['&#32;1. ordered', '1\. ordered'],
            // A definition reaches no output at all rather than merely becoming
            // another block, so it takes an escape of its own.
            'an uncovered reference definition' => ['&#32;[foo]: /url', '\[foo]: /url'],
            // The uncovered opener is itself a decoded character, a frozen span
            // by the time the drop happens.
            'an uncovered decoded quote marker' => ['&#32;&gt; quote', '\> quote'],
            // Past a container marker, the head of the CONTENT is a block head too.
            'inside a quote' => ['> &#32;in quote', '> in quote'],
            'inside a list item' => ['- &#32;in item', '- in item'],
        ];
    }

    #[DataProvider('drops')]
    public function testTheWhitespaceIsDropped(string $markdown, string $carve): void
    {
        $this->assertSame($carve . "\n", (new MarkdownToCarve())->convert($markdown . "\n"));
    }

    #[DataProvider('drops')]
    public function testTheDropIsReported(string $markdown, string $carve): void
    {
        $rows = array_values(array_filter(
            (new MarkdownToCarve())->convertWithFidelityReport($markdown . "\n")->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->message === MarkdownToCarve::LEADING_WHITESPACE_UNSPELLABLE,
        ));

        $this->assertCount(1, $rows, 'converting to ' . $carve);
        $this->assertSame('structure-unspellable', $rows[0]->code);
        $this->assertSame('warning', $rows[0]->severity);
        $this->assertSame('dropped', $rows[0]->fidelity);
        $this->assertSame('exact', $rows[0]->confidence);
    }

    /**
     * The row names a line, which is the whole point of reporting it: a caller
     * gating on `fidelity-unverified` learns only that there is no evidence.
     */
    public function testTheRowNamesTheSourceLine(): void
    {
        $rows = array_values(array_filter(
            (new MarkdownToCarve())->convertWithFidelityReport("first\n\n&#32;second\n")->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->message === MarkdownToCarve::LEADING_WHITESPACE_UNSPELLABLE,
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('line:3', $rows[0]->path);
    }

    /**
     * THE CONTROL. `&nbsp;` decodes to U+00A0, which is not a space or a tab, is
     * spellable at a line's head, and survives - so no row is owed there.
     */
    public function testANonBreakingSpaceIsNeitherDroppedNorReported(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("&nbsp;leading nbsp\n");

        $this->assertSame("\u{00a0}leading nbsp\n", $result->value);
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    /**
     * STATED, NOT ASSERTED AS CORRECT. A line that CONTINUES a paragraph is not
     * the head of a block, so the decode is left alone - and the Carve reader
     * then discards it unreported. carve-js draws the boundary in the same
     * place, so moving it is a cross-engine ruling rather than a fix here.
     */
    public function testAContinuationLineKeepsItsDecodedWhitespace(): void
    {
        $this->assertSame("x\n second line\n", (new MarkdownToCarve())->convert("x\n&#32;second line\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function headingDrops(): array
    {
        return [
            'the ticket shape' => ['# &#32;head', '# head'],
            'a level-2 heading' => ['## &#32;h2', '## h2'],
            'a level-6 heading' => ['###### &#32;h6', '###### h6'],
            'a run of references' => ['# &#32;&#32;two', '# two'],
            // Past the marker there is no block to open, so nothing is escaped.
            'an uncovered bullet' => ['# &#32;- x', '# - x'],
            'a heading inside a quote' => ['> # &#32;q', '> # q'],
        ];
    }

    #[DataProvider('headingDrops')]
    public function testTheWhitespaceIsDroppedAtAHeadingHead(string $markdown, string $carve): void
    {
        $this->assertSame($carve . "\n", (new MarkdownToCarve())->convert($markdown . "\n"));
    }

    #[DataProvider('headingDrops')]
    public function testTheHeadingDropIsReported(string $markdown, string $carve): void
    {
        $rows = array_values(array_filter(
            (new MarkdownToCarve())->convertWithFidelityReport($markdown . "\n")->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->message === MarkdownToCarve::HEADING_LEADING_WHITESPACE_UNSPELLABLE,
        ));

        $this->assertCount(1, $rows, 'converting to ' . $carve);
        $this->assertSame('structure-unspellable', $rows[0]->code);
        $this->assertSame('warning', $rows[0]->severity);
        $this->assertSame('dropped', $rows[0]->fidelity);
        $this->assertSame('exact', $rows[0]->confidence);
        $this->assertSame('line:1', $rows[0]->path);
    }

    /**
     * THE CONTROL at a heading head. U+00A0 is a real character Carve holds,
     * so it survives and owes no row (markup-carve/carve-rs#2449).
     */
    public function testANonBreakingSpaceSurvivesAHeadingHead(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("# &nbsp;head\n");

        $this->assertSame("# \u{00a0}head\n", $result->value);
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    /**
     * Whitespace a decode puts PAST the head of a heading is content, and is
     * left exactly as it decoded.
     */
    public function testADecodedSpaceAwayFromTheHeadIsKept(): void
    {
        $this->assertSame("# mid  x\n", (new MarkdownToCarve())->convert("# mid &#32;x\n"));
    }

    /**
     * A separator run the author wrote themselves is normalized by the reader,
     * not dropped here, so nothing is reported for it.
     */
    public function testAnAuthoredSeparatorRunIsNotReported(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("#    lit\n");

        $this->assertSame("# lit\n", $result->value);
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    public function testADecodedTabSurvivesAHeadingHead(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("# &#9;tab\n");

        $this->assertSame("# \ttab\n", $result->value);
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    public function testAnEmptiedHeadingRemainsAHeading(): void
    {
        $this->assertSame("# ` `{=html}\n", (new MarkdownToCarve())->convert("# &#32;\n"));
        $this->assertSame("> # ` `{=html}\n", (new MarkdownToCarve())->convert("> # &#32;\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function whitespaceOnlyHeadings(): array
    {
        return [
            'space' => ["# &#32;\n", ' '],
            'tab' => ["# &#9;\n", "\t"],
            'space then tab' => ["# &#32;&#9;\n", " \t"],
            'tab then space' => ["# &#9;&#32;\n", "\t "],
            'quoted' => ["> # &#32;\n", ' '],
            'list item' => ["- # &#32;\n", ' '],
            'ordered item' => ["1. # &#32;\n", ' '],
            'quoted item' => ["> - # &#32;\n", ' '],
            'setext item' => ["- &#32;\n  ===\n", ' '],
            'setext after paragraph' => ["- a\n\n  &#32;\n  ===\n", ' '],
        ];
    }

    #[DataProvider('whitespaceOnlyHeadings')]
    public function testWhitespaceOnlyHeadingContentSurvivesContainers(string $markdown, string $whitespace): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $html = (new CarveConverter())->convert($result->value);

        $this->assertMatchesRegularExpression('/<h1[^>]*>' . preg_quote($whitespace, '/') . '<\/h1>/', $html);
        if (str_contains($markdown, '- ') || str_starts_with($markdown, '1. ')) {
            $this->assertSame(1, substr_count($html, '<li>'));
        }
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped' && $row->confidence === 'exact',
        )));
        $this->assertCount(1, array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => str_starts_with($row->message, 'Preserved whitespace-only heading'),
        )));
    }

    /**
     * Raised by codex review. A `#` run in a table CELL is literal text, so the
     * space after it is content Carve holds and carve-rs keeps.
     */
    public function testAHeadingShapedTableCellIsLeftAlone(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("| # &#32;x |\n| --- |\n| a |\n");

        $this->assertSame("|= #  x |\n| a |\n", $result->value);
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    /**
     * A decoded separator changes the BLOCK, not a character: CommonMark
     * decides the block before any reference is decoded, so `#&#32;nosep` is a
     * paragraph whose text begins with `#`. Written bare it read back as a
     * level-1 heading, with an id and a place in the outline
     * (markup-carve/carve-php#3058). Every expectation here is byte-identical
     * to carve-rs 879d32c0a and to carve-js.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function decodedSeparators(): array
    {
        return [
            'level 1' => ["#&#32;nosep\n", "\\# nosep\n", "<p># nosep</p>\n"],
            'level 2' => ["##&#32;x\n", "\\#\\# x\n", "<p>## x</p>\n"],
            'level 6' => ["######&#32;x\n", "\\#\\#\\#\\#\\#\\# x\n", "<p>###### x</p>\n"],
            'a hex reference' => ["#&#x20;y\n", "\\# y\n", "<p># y</p>\n"],
            'inside a quote' => ["> #&#32;x\n", "> \\# x\n", "<blockquote><p># x</p></blockquote>\n"],
            'inside a list item' => ["- #&#32;x\n", "- \\# x\n", "<ul>\n  <li># x</li>\n</ul>\n"],
        ];
    }

    #[DataProvider('decodedSeparators')]
    public function testADecodedSeparatorKeepsTheParagraph(string $markdown, string $carve, string $html): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        $this->assertSame($carve, $result->value);
        $this->assertSame($html, CarveConverter::create()->convert($result->value));
        $this->assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->fidelity === 'dropped',
        )));
    }

    /**
     * Only a SPACE opens a Carve heading, so a decoded tab needs no escape and
     * neither does a run of seven.
     *
     * @return array<string, array{string, string}>
     */
    public static function separatorsThatOpenNoHeading(): array
    {
        return [
            'a numeric tab' => ["#&#9;x\n", "#\tx\n"],
            'a named tab' => ["#&Tab;x\n", "#\tx\n"],
            'a run of seven' => ["#######&#32;x\n", "####### x\n"],
        ];
    }

    #[DataProvider('separatorsThatOpenNoHeading')]
    public function testASeparatorThatOpensNoHeadingIsLeftBare(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * A marker run alone on its line is already a paragraph, so the separator
     * goes and the run stays bare.
     */
    public function testAMarkerRunTheDecodeEmptiedStaysBare(): void
    {
        $this->assertSame("#\n", (new MarkdownToCarve())->convert("#&#32;\n"));
        $this->assertSame("##\n", (new MarkdownToCarve())->convert("##&#32;\n"));
    }

    /**
     * The carve-php#3057 control: the SOURCE supplies the separator, so the
     * line IS a heading and must keep dropping the decoded space and naming it.
     */
    public function testTheSourceSuppliedSeparatorStillDropsAndReports(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("# &#32;head\n");

        $this->assertSame("# head\n", $result->value);
        $dropped = array_values(array_filter(
            $result->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->code === 'structure-unspellable',
        ));
        $this->assertCount(1, $dropped);
        $this->assertSame('warning', $dropped[0]->severity);
        $this->assertSame('dropped', $dropped[0]->fidelity);
        $this->assertSame('exact', $dropped[0]->confidence);
    }

    /**
     * An authored escape and an authored heading are both untouched.
     */
    public function testAuthoredLinesAreUnchanged(): void
    {
        $this->assertSame("\\# para\n", (new MarkdownToCarve())->convert("\\# para\n"));
        $this->assertSame("# x\n", (new MarkdownToCarve())->convert("# x\n"));
    }

    /**
     * A LATER line of a paragraph opens a heading at column 0 just as its
     * first one does. Raised by codex review on the carve-js twin.
     *
     * @return array<string, array{string, string}>
     */
    public static function continuationLines(): array
    {
        return [
            'level 1' => ["foo\n#&#32;x\n", "foo\n\\# x\n"],
            'level 2' => ["foo\n##&#32;x\n", "foo\n\\#\\# x\n"],
            'inside a quote' => ["> foo\n> #&#32;x\n", "> foo\n> \\# x\n"],
            // The SOURCE opens a heading, which interrupts the paragraph.
            'a source heading' => ["foo\n# x\n", "foo\n\n# x\n"],
            'an authored escape' => ["foo\n\\# x\n", "foo\n\\# x\n"],
        ];
    }

    #[DataProvider('continuationLines')]
    public function testAContinuationLineKeepsItsParagraph(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * A `#` run in a table CELL is literal text, so no escape is owed there.
     */
    public function testADecodedSeparatorInATableCellIsLeftAlone(): void
    {
        $this->assertSame("|= a |\n| # c |\n", (new MarkdownToCarve())->convert("| a |\n| --- |\n| #&#32;c |\n"));
    }
}
