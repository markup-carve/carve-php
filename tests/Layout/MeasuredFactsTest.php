<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Layout;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasuredFactsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $ids = [
            'CC-384', 'CC-385', 'CC-386', 'CC-387', 'CC-388', 'CC-389', 'CC-390',
            'CC-391', 'CC-395', 'CC-427', 'CC-431', 'CC-432', 'CC-433', 'CC-434',
        ];
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            if (in_array($case['id'] ?? null, $ids, true)) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testMeasuredFactsFromPinnedCase(array $case): void
    {
        $actual = Carve::parseWithLayout($case['input'])->layout;
        self::assertIsArray($actual['nodes'] ?? null);
        $nodes = [];
        foreach ($actual['nodes'] as $entry) {
            $nodes[$entry['path']] = $entry;
        }
        foreach ($case['expected']['layout']['nodes'] as $wanted) {
            $entry = $nodes[$wanted['path']] ?? null;
            self::assertIsArray($entry, $case['id'] . ' ' . $wanted['path']);
            foreach ($wanted as $name => $value) {
                self::assertSame($value, $entry[$name] ?? null, $case['id'] . ' ' . $wanted['path'] . ' ' . $name);
            }
        }
    }

    public function testQuotedFenceKeepsItsClosingRun(): void
    {
        $layout = Carve::parseWithLayout("> ```\n> x\n> ```\n")->layout;
        $fence = array_values(array_filter(
            $layout['nodes'],
            static fn (array $entry): bool => $entry['path'] === '/children/0/children/0',
        ))[0];
        self::assertSame('```', $fence['openerRaw']);
        self::assertSame('```', $fence['closerRaw']);
    }
}
