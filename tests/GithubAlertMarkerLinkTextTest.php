<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class GithubAlertMarkerLinkTextTest extends TestCase
{
    public function testLinkLabelsAreQuietButLiteralLabelsAreReported(): void
    {
        $source = "> [!NOTE][] and [!TIP]\n> x\n\n[!NOTE]: /u\n";
        $warnings = (new AuthoringLinter())->lint($source);

        self::assertSame(['github-alert-marker'], array_map(static fn ($warning): string => $warning->rule, $warnings));
        self::assertSame(1, $warnings[0]->line);
        self::assertSame(17, $warnings[0]->column);
    }

    public function testUnresolvedReferenceLabelStillReportsAsLiteral(): void
    {
        $warnings = (new AuthoringLinter())->lint("> [!NOTE][]\n> x\n");

        self::assertSame(['github-alert-marker'], array_map(static fn ($warning): string => $warning->rule, $warnings));
    }
}
