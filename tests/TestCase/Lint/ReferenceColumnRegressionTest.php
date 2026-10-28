<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\ReferenceLinter;
use PHPUnit\Framework\TestCase;

final class ReferenceColumnRegressionTest extends TestCase
{
    public function testUnusedDefinitionStartsAtItsAuthoredBracket(): void
    {
        foreach (
            [
                "[r]: /u\n" => [1, 1],
                "> [r]: /u\n" => [1, 3],
                "- a\n  [r]: /u\n more\ntail\n" => [2, 3],
            ] as $source => [$line, $column]
        ) {
            $warnings = array_values(array_filter(
                (new ReferenceLinter())->lint($source),
                static fn ($warning): bool => $warning->rule === 'unused-link-reference-definition',
            ));

            self::assertCount(1, $warnings, $source);
            self::assertSame([$line, $column], [$warnings[0]->line, $warnings[0]->column]);
        }
    }
}
