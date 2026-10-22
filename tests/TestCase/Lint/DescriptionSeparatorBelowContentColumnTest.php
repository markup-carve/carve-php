<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionSeparatorBelowContentColumnTest extends TestCase
{
    /**
     * @var string
     */
    private const RULE = 'description-separator-below-content-column';

    /**
     * @return array<string, array{string, array<int, array{line: int, column: int, includes: array<string>}>}>
     */
    public static function vectors(): array
    {
        $path = __DIR__ . '/../../Fixture/lint-rule-vectors/' . self::RULE . '.json';
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($data['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector['source'], $vector['expected']['findings']];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function testVector(string $source, array $expected): void
    {
        $warnings = $this->findings($source);
        self::assertSame(
            array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
            array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings),
        );
        foreach ($expected as $index => $finding) {
            foreach ($finding['includes'] as $fragment) {
                self::assertStringContainsString($fragment, $warnings[$index]->message);
            }
        }
    }

    /**
     * @return array<string, array{string, int, int, string, int}>
     */
    public static function markerRanges(): array
    {
        return [
            'CRLF' => ["- a\r\n  :: term\r\n : detail\r\n", 3, 2, ':', 2],
            'tab' => ["- a\n\t:: term\n : detail\n", 3, 2, ':', 4],
            'quote' => ["> - a\n>   :: term\n>  : detail\n", 3, 4, ':', 4],
            'quoted blank' => ["> - a\n>   :: term\n>\n>  : detail\n", 4, 4, ':', 4],
            'term marker' => ["- a\n  :: term\n :: next\n", 3, 2, '::', 2],
        ];
    }

    #[DataProvider('markerRanges')]
    public function testMarkerRange(string $source, int $line, int $column, string $marker, int $target): void
    {
        $warnings = $this->findings($source);
        self::assertCount(1, $warnings);
        $warning = $warnings[0];
        self::assertSame([$line, $column], [$warning->line, $warning->column]);
        self::assertStringContainsString("column {$target}", $warning->message);
        self::assertSame($marker, substr($source, $warning->start, $warning->end - $warning->start));
        self::assertSame(strlen($marker), $warning->end - $warning->start);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ignored(): array
    {
        return [
            'escaped colon' => ["- a\n  :: term\n \\: detail\n"],
            'triple colon' => ["- a\n  :: term\n ::: note\n"],
            'code payload' => ["- a\n  :: term\n  ```\n  : detail\n  ```\n"],
        ];
    }

    #[DataProvider('ignored')]
    public function testIgnored(string $source): void
    {
        self::assertSame([], $this->findings($source));
    }

    /**
     * @return array<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function findings(string $source): array
    {
        return array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === self::RULE,
        ));
    }
}
