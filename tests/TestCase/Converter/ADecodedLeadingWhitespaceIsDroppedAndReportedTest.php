<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

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
}
