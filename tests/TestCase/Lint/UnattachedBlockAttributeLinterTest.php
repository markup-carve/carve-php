<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\TestCase;

class UnattachedBlockAttributeLinterTest extends TestCase
{
    public function testContainerEndsReportTheAuthoredAttributeRun(): void
    {
        $cases = [
            ["{.a}\n", 1, 1, '{.a}'],
            ["é😀\r\n\r\n> {.a}\r\n", 3, 3, '{.a}'],
            ["- {.a\n  .b}\ntail\n", 1, 3, "{.a\n  .b}"],
            ["::: note\n{.a}\n:::\n", 2, 1, '{.a}'],
            [":: term\n: {.a}\n", 2, 3, '{.a}'],
            ["note[^n]\n\n[^n]: {.a}\n", 3, 7, '{.a}'],
            ["{.a}\n%% hidden\n", 1, 1, '{.a}'],
        ];
        foreach ($cases as [$source, $line, $column, $text]) {
            $warnings = array_values(array_filter(
                (new SourceLinter())->lint($source),
                static fn ($warning) => $warning->rule === 'unattached-block-attribute',
            ));
            $this->assertCount(1, $warnings, $source);
            $warning = $warnings[0];
            $this->assertSame([$line, $column], [$warning->line, $warning->column], $source);
            $this->assertSame($text, substr($source, $warning->start, $warning->end - $warning->start));
        }
    }

    public function testAttributesCanReachTheNextListBodyChunk(): void
    {
        foreach (["- a\n  {.x}\n  - b\n", "- para\n  {.k}\n\n  more\n", "{.a}\n%% hidden\n\n# Title\n"] as $source) {
            $this->assertNotContains('unattached-block-attribute', array_column((new SourceLinter())->lint($source), 'rule'), $source);
        }
    }

    public function testCallsDoNotSharePendingRuns(): void
    {
        $linter = new SourceLinter();
        $this->assertCount(1, $linter->lint('{.a}'));
        $this->assertSame([], $linter->lint('text'));
    }

    public function testFallbackFenceReportsBothDelimiters(): void
    {
        $warnings = (new SourceLinter())->lint(" ```\n code\n ```\n");
        $this->assertSame([1, 3], array_column($warnings, 'line'));
        $this->assertSame(['fence-delimiter-indentation', 'fence-delimiter-indentation'], array_column($warnings, 'rule'));
        $this->assertSame([], (new SourceLinter())->lint("`a\n```\n`\n"));
    }

    public function testQuotedTitleWithInvalidSeparatorReportsDroppedMetadata(): void
    {
        $rules = array_column((new SourceLinter())->lint("::: note \t\"Title\"\nx\n:::\n"), 'rule');
        $this->assertNotContains('block-marker-as-text', $rules);
        $this->assertContains('fence-title-syntax', $rules);
    }

    public function testDefinitionMarkersDoNotHideLaterListWarnings(): void
    {
        $source = "- intro\n\n  :: term\n  :  definition\n   > quote\n";
        $warnings = (new SourceLinter())->lint($source);
        $this->assertSame([5], array_column($warnings, 'line'));
        $this->assertSame(['list-item-block-overindented'], array_column($warnings, 'rule'));
    }

    public function testFenceTrackingEndsWithTheOwningItem(): void
    {
        foreach (['```', '~~~'] as $fence) {
            $source = "- a\n  - b\n\n    $fence\n    p\n $fence\n\n    tail\n";
            $warnings = (new SourceLinter())->lint($source);
            $this->assertSame([6], array_column($warnings, 'line'));
            $this->assertSame(['list-item-body-detached'], array_column($warnings, 'rule'));
        }
    }

    public function testMigrationHabitsAreLiteralTextWithCodepointColumns(): void
    {
        $warnings = (new SourceLinter())->lint("+ bullet\n\né😀 ^word^\n");
        $this->assertSame(['djot-plus-bullet', 'djot-superscript-caret'], array_column($warnings, 'rule'));
        $this->assertSame([1, 4], array_column($warnings, 'column'));
        foreach (["`^code^`\n", "{^sup^}\n", "\\^escaped^\n", "| a | b |\n+ c | d |\n", "[link](/^path^)\n"] as $source) {
            $this->assertSame([], (new SourceLinter())->lint($source), $source);
        }
    }

    public function testSameLineNestedMarkersKeepTheirOwnColumns(): void
    {
        $this->assertSame([], (new SourceLinter())->lint("- > - x\n    [r]: /url\n\nSee [r][].\n"));
        $warnings = (new SourceLinter())->lint("- - x\n   [r]: /url\n\nSee [r][].\n");
        $this->assertSame(['list-item-body-detached'], array_column($warnings, 'rule'));
    }

    public function testHabitWarningsCrossInlineNodesAndSoftBreaks(): void
    {
        foreach (['x^a "b"^', "a ^b\nc^ d"] as $source) {
            $warnings = (new SourceLinter())->lint($source);
            $this->assertContains('djot-superscript-caret', array_column($warnings, 'rule'));
        }
        $linter = new MarkdownHabitLinter();
        foreach (['~~>x~~', "a ~~b\nc~~ d", 'a **b `c` d** e'] as $source) {
            $this->assertNotEmpty($linter->lint($source), $source);
        }
        foreach (['{~~b~} x {~~c~}', 'a **b* c**', "a ~~b\n\nc~~ d"] as $source) {
            $this->assertSame([], $linter->lint($source), $source);
        }
    }
}
