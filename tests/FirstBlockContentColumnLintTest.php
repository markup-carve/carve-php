<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirstBlockContentColumnLintTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function followers(): array
    {
        return [
            'bullet' => ["- +\n  x\n", false],
            'ordered' => ["1. +\n   x\n", false],
            'wide ordered' => ["10. +\n    x\n", false],
            'nested' => ["- a\n  - +\n    x\n", false],
            'past content column' => ["- +\n   x\n", true],
        ];
    }

    #[DataProvider('followers')]
    public function testOnlyARefusedFollowerGetsTheWarning(string $source, bool $refused): void
    {
        $warnings = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'continuation-marker-refused',
        ));
        self::assertCount($refused ? 1 : 0, $warnings);
    }
}
