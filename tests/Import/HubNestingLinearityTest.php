<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: league/commonmark opened a run of containers on one line in quadratic time, so a Markdown or GFM import
 * of 32 KB of `> - ` took 7 s before it was refused for its depth (C2-A-171).
 */
final class HubNestingLinearityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'gfm quote in item' => ['gfm', '> - '],
            'gfm items' => ['gfm', '- '],
            'markdown quotes' => ['markdown', '>'],
            'markdown ordered in quote' => ['markdown', '> 1. '],
        ];
    }

    #[DataProvider('shapes')]
    public function testRefusalIsLinear(string $from, string $unit): void
    {
        $small = str_repeat($unit, 500) . "a\n";
        $large = str_repeat($unit, 4000) . "a\n";
        $excess = Linearity::excess(static fn () => self::refused($small, $from), static fn () => self::refused($large, $from), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    #[DataProvider('shapes')]
    public function testRefusesPastTheBlockDepth(string $from, string $unit): void
    {
        $this->expectException(ImportInputError::class);

        Carve::importDocument(str_repeat($unit, Tables::BLOCK_DEPTH + 1) . "a\n", ['from' => $from]);
    }

    public function testKeepsTheDeepestTreeAllowed(): void
    {
        $result = Carve::importDocument(str_repeat('>', Tables::BLOCK_DEPTH) . " a\n", ['from' => 'gfm']);

        $this->assertStringEndsWith("a\n", $result->source);
    }

    private static function refused(string $source, string $from): bool
    {
        try {
            Carve::importDocument($source, ['from' => $from]);
        } catch (ImportInputError) {
            return true;
        }

        return false;
    }
}
