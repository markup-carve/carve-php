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
}
