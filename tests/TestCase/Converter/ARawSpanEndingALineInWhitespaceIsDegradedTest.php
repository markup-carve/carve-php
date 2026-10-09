<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\TestCase;

/**
 * A raw span the Markdown importer writes may end a content line in
 * whitespace, which CARVE-P2-025 drops (markup-carve/carve#2804).
 */
class ARawSpanEndingALineInWhitespaceIsDegradedTest extends TestCase
{
    /**
     * @return list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private function rows(string $markdown): array
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        return array_values(array_filter(
            $report->diagnostics,
            static fn (MigrationDiagnostic $diagnostic): bool => $diagnostic->code === 'raw-span-whitespace-trimmed',
        ));
    }

    public function testReportsTheLossWithTheFieldsTheRulingNames(): void
    {
        $rows = $this->rows("intro\n\n<a href=\"foo  \nbar\">\n");
        $this->assertCount(1, $rows);
        $this->assertSame('raw-span-whitespace-trimmed', $rows[0]->code);
        $this->assertSame(
            'A raw span ends a content line in whitespace, which Carve drops; '
                . 'the whitespace did not reach the converted source',
            $rows[0]->message,
        );
        $this->assertSame('warning', $rows[0]->severity);
        $this->assertSame('degraded', $rows[0]->fidelity);
        $this->assertSame('exact', $rows[0]->confidence);
        $this->assertSame('line:3', $rows[0]->path);
    }

    public function testReportsATabTheSameWay(): void
    {
        $rows = $this->rows("<a href=\"foo\t\nbar\">\n");
        $this->assertCount(1, $rows);
        $this->assertSame('degraded', $rows[0]->fidelity);
    }

    public function testCountsTheLineInTheInputNotInTheFoldedArray(): void
    {
        // The reference definition is moved before the body is walked, so an
        // index into the folded lines would name line 2 here.
        $rows = $this->rows("[a]: /x\n\n<a href=\"foo  \nbar\">\n");
        $this->assertCount(1, $rows);
        $this->assertSame('line:3', $rows[0]->path);
    }

    public function testStaysSilentWhereTheWhitespaceIsNotAtALineEnd(): void
    {
        $this->assertSame([], $this->rows("x <a href=\"foo  bar\"> y\n"));
    }

    public function testStaysSilentWhereTheRawSpanHasNoTrailingWhitespace(): void
    {
        $this->assertSame([], $this->rows("x <a href=\"foo\">\n"));
    }

    public function testNamesALossTheEngineReallyTakes(): void
    {
        $value = (new MarkdownToCarve())->convert("<a href=\"foo  \nbar\">\n");
        $this->assertStringContainsString("foo  \n", $value);
        $this->assertStringContainsString("<a href=\"foo\nbar\">", (new CarveConverter())->convert($value));
    }

    public function testReachesTheDefaultLossGateWhereACleanDocumentReachesNothing(): void
    {
        $gated = static function (string $markdown): array {
            $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

            return array_values(array_map(
                static fn (MigrationDiagnostic $diagnostic): string => $diagnostic->code,
                array_filter(
                    $report->diagnostics,
                    static fn (MigrationDiagnostic $diagnostic): bool => in_array($diagnostic->fidelity, ['degraded', 'dropped'], true),
                ),
            ));
        };
        $this->assertContains('raw-span-whitespace-trimmed', $gated("<a href=\"foo  \nbar\">\n"));
        $this->assertSame([], $gated("one two\n"));
    }
}
