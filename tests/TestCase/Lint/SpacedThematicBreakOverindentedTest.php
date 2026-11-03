<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5003: a spaced thematic break is an opener like `***`.
 */
final class SpacedThematicBreakOverindentedTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{int, int}>}>
     */
    public static function reported(): array
    {
        return [
            'dashes' => ["  - - -\n", [[1, 3]]],
            'stars' => ["  * * *\n", [[1, 3]]],
            'four dashes' => [" - - - -\n", [[1, 2]]],
            'after a paragraph and a blank' => ["para\n\n  * * *\n", [[3, 3]]],
            'interrupting a paragraph' => ["para\n  - - -\n", [[2, 3]]],
            'in a quote' => ["> x\n>\n>   - - -\n", [[3, 5]]],
            'in a footnote' => ["x[^1]\n\n[^1]: n\n\n      - - -\n", [[5, 7]]],
            'in a description' => ["term\n: dd\n\n     * * *\n", [[4, 6]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{int, int}> $expected
     */
    #[DataProvider('reported')]
    public function testReported(string $source, array $expected): void
    {
        self::assertSame($expected, $this->positions($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quiet(): array
    {
        return [
            'at column 1' => ["- - -\n"],
            'right after a quote marker' => ["> - - -\n"],
            'a list item' => ["  - - a\n"],
            'a star list item' => ["  * * a\n"],
            'spaced underscores' => ["  _ _ _\n"],
            'two dashes' => ["  - -\n"],
            'in a list item' => ["- a\n\n      - - -\n"],
        ];
    }

    #[DataProvider('quiet')]
    public function testQuiet(string $source): void
    {
        self::assertSame([], $this->positions($source));
    }

    public function testTheMessageNamesTheThematicBreakAndItsOwner(): void
    {
        $warnings = $this->findings("  - - -\n");

        self::assertStringContainsString('thematic break', $warnings[0]->message);
        self::assertStringContainsString('the document', $warnings[0]->message);
    }

    /**
     * @return array<array{int, int}>
     */
    private function positions(string $source): array
    {
        return array_map(static fn (LintWarning $w): array => [$w->line, $w->column], $this->findings($source));
    }

    /**
     * @return array<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function findings(string $source): array
    {
        return array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn (LintWarning $warning): bool => $warning->rule === 'block-opener-overindented',
        ));
    }
}
