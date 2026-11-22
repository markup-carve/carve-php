<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Import\Hub\InlineBudgetParser;
use MarkupCarve\Carve\Import\ImportInputError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5615: league/commonmark keeps 1.3 to 2.7 KB per inline candidate position of a block, so 100,000 brackets
 * on one line took 173 MB. The hub refuses a block past the bound instead.
 */
final class HubCandidateBudgetTest extends TestCase
{
    private const BOUND = InlineBudgetParser::MAX_CANDIDATES;

    /**
     * @return array<string, array{string}>
     */
    public static function formats(): array
    {
        return ['markdown' => ['markdown'], 'gfm' => ['gfm']];
    }

    #[DataProvider('formats')]
    public function testKeepsALineAtTheBound(string $from): void
    {
        $line = str_repeat('[', self::BOUND);

        $this->assertSame($line . "\n", Carve::importDocument($line . "\n", ['from' => $from])->source);
    }

    #[DataProvider('formats')]
    public function testRefusesALinePastTheBound(string $from): void
    {
        $this->expectException(ImportInputError::class);
        $this->expectExceptionMessage($from . ': an inline block holds more than ' . self::BOUND);

        Carve::importDocument(str_repeat('[', self::BOUND + 1) . "\n", ['from' => $from]);
    }

    #[DataProvider('formats')]
    public function testCountsAParagraphAcrossItsLines(string $from): void
    {
        $this->expectException(ImportInputError::class);

        Carve::importDocument(self::lines(str_repeat('a*', self::BOUND + 1), "\n"), ['from' => $from]);
    }

    public function testCountsLineBreaks(): void
    {
        $this->expectException(ImportInputError::class);

        Carve::importDocument(str_repeat("a\n", self::BOUND + 2), ['from' => 'gfm']);
    }

    #[DataProvider('formats')]
    public function testCountsLazyLinesOfAQuote(string $from): void
    {
        $this->expectException(ImportInputError::class);

        Carve::importDocument('> ' . self::lines(str_repeat('[', self::BOUND + 1), "\n"), ['from' => $from]);
    }

    #[DataProvider('formats')]
    public function testCountsEachBlockOnItsOwn(string $from): void
    {
        $source = str_repeat('[', self::BOUND) . "\n\n" . str_repeat('[', self::BOUND) . "\n";

        $this->assertSame($source, Carve::importDocument($source, ['from' => $from])->source);
    }

    #[DataProvider('formats')]
    public function testCountsABlockThatInterruptsAParagraphOnItsOwn(string $from): void
    {
        $source = str_repeat('[', self::BOUND - 384) . "\n# " . str_repeat('[', 500) . "\n";

        $this->assertStringEndsWith(str_repeat('[', 500) . "\n", Carve::importDocument($source, ['from' => $from])->source);
    }

    public function testCountsEachTightListItemOnItsOwn(): void
    {
        $item = '- [a](b) [c](d) ' . str_repeat('*', 64) . "\n";
        $result = Carve::importDocument(str_repeat($item, intdiv(2 * self::BOUND, 70)), ['from' => 'gfm']);

        $this->assertStringStartsWith('- [a](b) [c](d) ', $result->source);
    }

    public function testRefusesLinesPastTheNestingLevelOfTheLibrary(): void
    {
        $this->expectException(ImportInputError::class);
        $this->expectExceptionMessage('nesting limits');

        Carve::importDocument(str_repeat(str_repeat('>', 210) . ' ' . str_repeat('[', 80) . "\n", 3000), ['from' => 'gfm']);
    }

    public function testRefusesATableRowPastTheBound(): void
    {
        $this->expectException(ImportInputError::class);
        $this->expectExceptionMessage('inline candidate positions');

        Carve::importDocument("| a |\n|---|\n| " . str_repeat('[', self::BOUND + 1) . " |\n", ['from' => 'gfm']);
    }

    public function testKeepsATableRowAtTheBound(): void
    {
        $result = Carve::importDocument("| a |\n|---|\n|" . str_repeat('[', self::BOUND - 2) . "|\n", ['from' => 'gfm']);

        $this->assertStringContainsString(str_repeat('[', self::BOUND - 2), $result->source);
    }

    public function testDoesNotCountCode(): void
    {
        $source = "```\n" . str_repeat('[*_', self::BOUND) . "\n```\n";

        $this->assertSame($source, Carve::importDocument($source, ['from' => 'gfm'])->source);
    }

    public function testRefusesBeforeTheLibraryParsesInlines(): void
    {
        $source = str_repeat('[', 200000) . "\n";
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        try {
            Carve::importDocument($source, ['from' => 'gfm']);
            $this->fail('not refused');
        } catch (ImportInputError) {
        }

        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    private static function lines(string $text, string $break): string
    {
        return implode($break, str_split($text, 80)) . "\n";
    }
}
