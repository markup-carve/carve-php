<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HiddenCommentHabitLintTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function commentHosts(): array
    {
        return [
            'document' => ["%%%\n**hidden** ~~hidden~~\n%%%\n"],
            'quote' => ["> %%%\n> **hidden** ~~hidden~~\n> %%%\n"],
            'list' => ["- item\n  %%%\n  **hidden** ~~hidden~~\n  %%%\n"],
            'definition' => [":: term\n: definition\n  %%%\n  **hidden** ~~hidden~~\n  %%%\n"],
            'wide fence' => ["%%%%\n%%%\n**hidden** ~~hidden~~\n%%%%\n"],
        ];
    }

    #[DataProvider('commentHosts')]
    public function testHiddenCommentPayloadHasNoHabitWarnings(string $source): void
    {
        $warnings = (new MarkdownHabitLinter())->lint($source);
        $habits = array_filter($warnings, static fn ($warning): bool => str_starts_with($warning->rule, 'markdown-'));

        self::assertSame([], array_values($habits));
    }

    public function testVisibleSiblingKeepsItsOriginalPosition(): void
    {
        $source = "😀\n%%%\n**hidden**\n%%%\n\nafter **shown**\n";
        $warnings = (new MarkdownHabitLinter())->lint($source);

        self::assertCount(1, $warnings);
        self::assertSame(MarkdownHabitLinter::RULE_STRONG_ASTERISKS, $warnings[0]->rule);
        self::assertSame([6, 7], [$warnings[0]->line, $warnings[0]->column]);
        self::assertSame('**shown**', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testBidiControlInsideCommentIsStillReported(): void
    {
        $source = "%%%\n**hidden** \u{202E}\n%%%\n";
        $warnings = (new MarkdownHabitLinter())->lint($source);

        self::assertCount(1, $warnings);
        self::assertSame(MarkdownHabitLinter::RULE_BIDI_CONTROL_IN_SOURCE, $warnings[0]->rule);
    }

    public function testCodeFenceTextInsideCommentDoesNotHideTheFollowingParagraph(): void
    {
        $source = "%%%\n```\n**hidden**\n%%%\n\n**shown**\n";
        $warnings = (new MarkdownHabitLinter())->lint($source);

        self::assertCount(1, $warnings);
        self::assertSame(MarkdownHabitLinter::RULE_STRONG_ASTERISKS, $warnings[0]->rule);
        self::assertSame(6, $warnings[0]->line);
    }
}
