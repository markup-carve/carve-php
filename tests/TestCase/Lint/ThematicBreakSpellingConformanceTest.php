<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared vectors for the thematic-break-spelling hint (carve#4973).
 */
final class ThematicBreakSpellingConformanceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{line: int, column: int, includes: list<string>}>}>
     */
    public static function vectors(): array
    {
        $cases = [];
        foreach (['thematic-break-spelling'] as $rule) {
            $data = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/lint-rule-vectors/' . $rule . '.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['vectors'] as $vector) {
                $cases[$rule . ' ' . $vector['name']] = [$data['rule'], $vector['source'], $vector['expected']['findings']];
            }
        }

        return $cases;
    }

    /**
     * @param string $rule
     * @param string $source
     * @param list<array{line: int, column: int, includes: list<string>}> $expected
     */
    #[DataProvider('vectors')]
    public function testVector(string $rule, string $source, array $expected): void
    {
        $actual = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === $rule,
        ));

        self::assertSame(
            array_map(static fn (array $f): array => [$f['line'], $f['column']], $expected),
            array_map(static fn ($w): array => [$w->line, $w->column], $actual),
        );
        foreach ($expected as $i => $finding) {
            foreach ($finding['includes'] as $word) {
                self::assertStringContainsString($word, $actual[$i]->message);
            }
        }
    }
}
