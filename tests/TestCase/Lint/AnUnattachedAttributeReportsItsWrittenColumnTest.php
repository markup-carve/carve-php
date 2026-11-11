<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The walk moves a body line to its body column; the warning keeps the written column (carve#5952).
 */
class AnUnattachedAttributeReportsItsWrittenColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function provider(): array
    {
        return [
            'after a note marker-line comment run' => ["x[^n]\n\n[^n]: {% a\n     b\n     z %}\n    {.x}\nplain\n", 6, 5],
            'after a description marker-line comment run' => [":: t\n:   {% a\n     {k=v}\n: next\n", 3, 6],
            'past a description body column' => [":: term\n:  definition\n    {.k}\ntail\n", 3, 5],
            'past a quote body column in an item' => ["- > para\n  >  {#x}\ntail\n", 2, 6],
            'past an item content column' => ["- a\n\n   {.c}\n", 3, 4],
        ];
    }

    #[DataProvider('provider')]
    public function testTheWrittenColumnIsReported(string $source, int $line, int $column): void
    {
        $warnings = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'unattached-block-attribute',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame([$line, $column], [$warnings[0]->line, $warnings[0]->column]);
        $this->assertSame('{', $source[$warnings[0]->start]);
    }
}
