<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class MarkdownEscapedAlertLabelTest extends TestCase
{
    public function testEscapedMarkdownLabelBecomesLintableLiteralText(): void
    {
        $converted = (new MarkdownToCarve())->convert("> \\[!NOTE]\n> x\n");

        self::assertSame("> [!NOTE]\n> x\n", $converted);
        self::assertSame(['github-alert-marker'], array_map(
            static fn ($warning): string => $warning->rule,
            (new AuthoringLinter())->lint($converted),
        ));
    }

    public function testEscapeStaysWhenRemovingItWouldCreateALink(): void
    {
        $converted = (new MarkdownToCarve())->convert("> foo \\[!NOTE](/u)\n> x\n");

        self::assertSame("> foo \\[!NOTE](/u)\n> x\n", $converted);
    }

    public function testSyntheticAlertGuardStaysWhenAnotherLabelWasEscaped(): void
    {
        $markdown = ">\n> [!NOTE]\n> &#x1;\n\n> \\[!TIP]\n> y\n";
        $converted = (new MarkdownToCarve())->convert($markdown);

        self::assertStringStartsWith("> \\[!NOTE]\n", $converted);
        self::assertStringContainsString("> [!TIP]\n> y", $converted);
    }

    public function testAuthoredPrivateUseCharacterReferenceSurvives(): void
    {
        $converted = (new MarkdownToCarve())->convert("> \\[!TIP]\n> &#xE000;\n");

        self::assertStringContainsString("\u{E000}", $converted);
        self::assertStringContainsString('> [!TIP]', $converted);
    }

    public function testEscapedHeadingLabelDoesNotChangeHeadingFragmentResolution(): void
    {
        $markdown = "# \\[!NOTE]\n\n[link](#note)\n\n> \\[!TIP]\n> x\n";
        $converted = (new MarkdownToCarve())->convert($markdown);

        self::assertStringContainsString('[link](#NOTE)', $converted);
        self::assertStringContainsString('> [!TIP]', $converted);
    }

    public function testEscapedLazyQuoteLabelBecomesLiteral(): void
    {
        $converted = (new MarkdownToCarve())->convert("> before\n\\[!NOTE]\n> after\n");

        self::assertSame("> before\n> [!NOTE]\n> after\n", $converted);
    }
}
