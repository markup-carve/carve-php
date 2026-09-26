<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The escape search re-renders states it already measured; each distinct
 * candidate must cost one parse of the whole document, not one per visit.
 */
final class TheEscapeSearchParsesEachCandidateOnceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'every block escalates' => [str_repeat("  ## H\n\n", 20)];
        yield 'every line holds a failing and an idle occurrence' => [str_repeat(" | a |\n", 20)];
        yield 'failing blocks among idle ones' => ["a\n\n" . str_repeat("  ## H\n\nplain *x* text\n\n - b\n\n", 8)];
    }

    #[DataProvider('documents')]
    public function testNoCandidateIsParsedTwice(string $source): void
    {
        $renderer = new class extends CarveRenderer {
            /**
             * @var array<string, int>
             */
            public array $parses = [];

            protected function canonicalTree(string $source): ?array
            {
                if ($this->treeCacheSource !== $source) {
                    $this->parses[$source] = ($this->parses[$source] ?? 0) + 1;
                }

                return parent::canonicalTree($source);
            }
        };

        $output = $renderer->render((new CarveConverter())->parse($source));

        self::assertSame(CarveConverter::toCarve($source), $output);
        self::assertGreaterThan(2, count($renderer->parses), 'the document must reach the escape search');
        self::assertSame([1], array_values(array_unique($renderer->parses)));
    }
}
