<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\TestCase;

class ListOwnedOpenerHasOneOverindentWarningTest extends TestCase
{
    public function testNestedColonOpenerReportsOnlyItsListOwner(): void
    {
        $source = "- ::: box\n   ::: note\n   x\n   :::\n  :::\n";
        $warnings = (new DocumentLinter())->lint($source);
        $overindent = array_values(array_filter($warnings, static fn ($warning): bool => str_contains($warning->rule, 'overindented')));

        self::assertSame([['list-item-block-overindented', 2, 4]], array_map(
            static fn ($warning): array => [$warning->rule, $warning->line, $warning->column],
            $overindent,
        ));
    }

    public function testOtherOwnersKeepTheGeneralRule(): void
    {
        $warnings = (new DocumentLinter())->lint("  # doc\n\n- a\n   # nested\n");
        $overindent = array_values(array_filter($warnings, static fn ($warning): bool => str_contains($warning->rule, 'overindented')));

        self::assertSame([['block-opener-overindented', 1, 3], ['list-item-block-overindented', 4, 4]], array_map(
            static fn ($warning): array => [$warning->rule, $warning->line, $warning->column],
            $overindent,
        ));
    }
}
