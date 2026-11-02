<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShapedSpanLinterTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function vectorProvider(): array
    {
        $vectors = [];
        foreach (['comment-shaped-emphasis', 'glob-shaped-strong'] as $rule) {
            $file = dirname(__DIR__, 2) . '/spec/tests/lint-rule-vectors/' . $rule . '.json';
            $data = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['vectors'] as $vector) {
                $vectors[$rule . ': ' . $vector['name']] = [$rule, json_encode($vector, JSON_THROW_ON_ERROR)];
            }
        }

        return $vectors;
    }

    #[DataProvider('vectorProvider')]
    public function testTheSpecVectors(string $rule, string $vector): void
    {
        $data = json_decode($vector, true, flags: JSON_THROW_ON_ERROR);
        $found = array_values(array_filter(
            (new DocumentLinter())->lint($data['source']),
            static fn (LintWarning $warning): bool => $warning->rule === $rule,
        ));
        $this->assertSame(
            array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $data['expected']['findings']),
            array_map(static fn (LintWarning $warning): array => [$warning->line, $warning->column], $found),
        );
    }

    public function testTheOffsetsAreBytes(): void
    {
        $warning = (new DocumentLinter())->lint("é /* foo */\n")[0];
        $this->assertSame(['comment-shaped-emphasis', 3, 3, 4], [$warning->rule, $warning->column, $warning->start, $warning->end]);
    }
}
