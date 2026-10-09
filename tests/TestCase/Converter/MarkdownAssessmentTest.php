<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownAssessment;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownAssessmentTest extends TestCase
{
    public function testOrdinaryConstructsHaveExactSourceLocations(): void
    {
        foreach (
            [
                ['# heading', 'markdown-atx-heading', 'preserved'],
                ["heading\n=======", 'markdown-setext-heading', 'normalized'],
                ['**strong**', 'markdown-strong', 'preserved'],
                ['~~gone~~', 'markdown-strikethrough', 'preserved'],
                ['    code', 'markdown-indented-code', 'normalized'],
                ['[label](https://example.org)', 'markdown-link', 'preserved'],
                ['<https://example.org>', 'markdown-autolink', 'normalized'],
                ['x &amp; y', 'markdown-entity', 'normalized'],
                ['x\\!', 'markdown-escape', 'normalized'],
                ["a  \nb", 'markdown-hard-break', 'normalized'],
                ["- [x] done\n", 'markdown-bullet-task', 'preserved'],
                ["| A | B |\n| --- | --- |\n| x | y |", 'markdown-table', 'preserved'],
            ] as [$source, $code, $fidelity]
        ) {
            $rows = (new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics;
            $matched = array_values(array_filter($rows, static fn ($row): bool => $row->code === $code));
            self::assertNotEmpty($matched, $source);
            self::assertSame($fidelity, $matched[0]->fidelity);
            self::assertSame('exact', $matched[0]->confidence);
            self::assertSame('line:1', $matched[0]->path);
            self::assertNotContains('fidelity-unverified', array_column($rows, 'code'));
        }
    }

    public function testCodeMarkersAreContentAndCrLfLocationsReferToOriginalInput(): void
    {
        $rows = (new MarkdownToCarve())->convertWithFidelityReport("```\r\n1. [x] **code**\r\n```\r\n\r\n**text**")->diagnostics;
        self::assertSame(['markdown-fenced-code', 'markdown-paragraph', 'markdown-strong'], array_column($rows, 'code'));
        self::assertSame(['line:1', 'line:5', 'line:5'], array_column($rows, 'path'));
    }

    public function testSourceLocationsAdvanceAcrossMultilineCodeSpans(): void
    {
        $rows = (new MarkdownToCarve())->convertWithFidelityReport("`a\nb` **text**")->diagnostics;
        $codes = array_column($rows, 'code');
        self::assertContains('markdown-code-span', $codes);
        self::assertContains('markdown-strong', $codes);
        self::assertSame('line:1', $rows[array_search('markdown-code-span', $codes, true)]->path);
        self::assertSame('line:2', $rows[array_search('markdown-strong', $codes, true)]->path);
    }

    public function testOrderedTaskLossesKeepTheirSourceLines(): void
    {
        $rows = (new MarkdownToCarve())->convertWithFidelityReport("```\ncode\n```\n\n1. [x] done\n2. [ ] next\n")->diagnostics;
        $losses = array_values(array_filter($rows, static fn ($row): bool => $row->fidelity === 'dropped'));
        self::assertSame(['structure-unspellable', 'structure-unspellable'], array_column($losses, 'code'));
        self::assertSame(['line:5', 'line:6'], array_column($losses, 'path'));
        self::assertNotContains('fidelity-unverified', array_column($rows, 'code'));
    }

    public function testOptInDialectsAndWideParagraphsFailClosedWithoutRepeatedSuffixCopies(): void
    {
        $result = (new MarkdownToCarve(convertHighlight: true))->convertWithFidelityReport('hello');
        self::assertSame('fidelity-unverified', $result->diagnostics[0]->code);
        $assessor = new MarkdownAssessment();
        self::assertFalse($assessor->assess(str_repeat('a! ', 330000), 'wrong')['complete']);
        self::assertFalse($assessor->assess('x ' . str_repeat('<!--', 240000), 'wrong')['complete']);
        self::assertFalse($assessor->assess(str_repeat('**' . str_repeat('é', 100) . "**\n\n", 5000), 'wrong')['complete']);
    }

    public function testIncompleteAssessmentAndChangedOutputFailClosed(): void
    {
        foreach (['https://example.org', "[^note]\n\n[^note]: note", "\0", "| a |\n| --- |\n| x | extra |"] as $source) {
            $rows = (new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics;
            self::assertContains('fidelity-unverified', array_column($rows, 'code'));
        }
        self::assertFalse((new MarkdownAssessment())->assess('**strong**', 'wrong')['complete']);
        self::assertFalse((new MarkdownAssessment())->assess('a  b', 'a b')['complete']);
    }
}
