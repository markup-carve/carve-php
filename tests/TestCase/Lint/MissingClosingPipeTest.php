<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class MissingClosingPipeTest extends TestCase
{
    public function testSingleOpeningPipeIsTableShapedParagraphText(): void
    {
        foreach (["| a\n", "> | a\n", "- | a\n"] as $source) {
            $warnings = array_values(array_filter(
                (new AuthoringLinter())->lint($source),
                static fn ($warning): bool => $warning->rule === 'table-shaped-paragraph',
            ));

            self::assertCount(1, $warnings, $source);
            self::assertSame(1, $warnings[0]->line);
        }
    }
}
