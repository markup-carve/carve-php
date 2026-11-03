<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cases beyond the shared vectors, from carve-js#3975.
 */
final class FragileConstructLinterTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'quoted table after a blank' => ['block-opener-overindented', "> x\n>\n>  | a |\n>  | b |\n", [[3, 4]]],
            'heading after a lone plus' => ['block-opener-overindented', "- a\n+\n# h\n", []],
            'multibyte before the marker' => ['emphasis-marker-before-attributes', "é*{.x}\n", [[1, 2]]],
            'marker inside inline math' => ['emphasis-marker-before-attributes', "*a $`*` b*{.x}\n", []],
        ];
    }

    /**
     * @param string $rule
     * @param string $source
     * @param array<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testCase(string $rule, string $source, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (LintWarning $w): array => [$w->line, $w->column],
            $this->findings($rule, $source),
        ));
    }

    public function testTheMessageNamesTheOwnerAndTheDistance(): void
    {
        $warnings = $this->findings('block-opener-overindented', "> q\n>   # h\n");

        self::assertStringContainsString('2 columns past the content column of its block quote', $warnings[0]->message);
    }

    public function testTheMessageNamesTheSpanItCloses(): void
    {
        $warnings = $this->findings('emphasis-marker-before-attributes', "*a *b*{.x}\n");

        self::assertStringContainsString('strong span that opens at line 1, column 1', $warnings[0]->message);
    }

    public function testOffsetsAreBytesIntoCrlfSource(): void
    {
        $warnings = $this->findings('emphasis-marker-before-attributes', "x\r\né*{.x}\r\n");

        self::assertSame([2, 2, 5, 10], [$warnings[0]->line, $warnings[0]->column, $warnings[0]->start, $warnings[0]->end]);
    }

    public function testABomShiftsNeitherColumnNorRange(): void
    {
        $source = "\u{FEFF}  # h\n";
        $warnings = $this->findings('block-opener-overindented', $source);

        self::assertSame([1, 3, '#'], [$warnings[0]->line, $warnings[0]->column, substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start)]);
    }

    /**
     * @return array<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function findings(string $rule, string $source): array
    {
        return array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn (LintWarning $warning): bool => $warning->rule === $rule,
        ));
    }
}
