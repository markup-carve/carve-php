<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\TableColumnLinter;
use PHPUnit\Framework\TestCase;

class TableColumnLinterTest extends TestCase
{
    public function testReportsTheFourColumnContracts(): void
    {
        $linter = new TableColumnLinter();
        $rules = static fn (array $warnings): array => array_map(static fn ($warning): string => $warning->rule, $warnings);
        self::assertSame(['table-alignment-run-padding'], $rules($linter->lint('|>text |')));
        self::assertContains('table-column-arity', $rules($linter->lint("{aligns=left}\n| a | b |")));
        self::assertContains('table-width-total', $rules($linter->lint("{widths=60,50}\n| a | b |")));
        self::assertContains('table-column-overlap', $rules($linter->lint("{aligns=left}\n|=> H |")));
    }
}
