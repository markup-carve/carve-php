<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Under rule A a marker below the item's content column starts a separate
 * list whether or not a blank line comes first (carve-php#4621).
 */
final class AMarkerRightAfterAnItemBelowItsContentColumnIsReportedTest extends TestCase
{
    public function testTheSharedVectors(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixture/lint-rule-vectors/list-marker-below-content-column.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['vectors']);
        foreach ($data['vectors'] as $vector) {
            self::assertIsArray($vector);
            self::assertIsString($vector['source']);
            self::assertIsArray($vector['expected']);
            self::assertIsArray($vector['expected']['findings']);
            $actual = $this->findings($vector['source']);
            $expected = [];
            foreach ($vector['expected']['findings'] as $finding) {
                self::assertIsArray($finding);
                $expected[] = [$finding['line'], $finding['column']];
            }
            self::assertSame($expected, array_map(static fn ($warning): array => [$warning->line, $warning->column], $actual), (string)$vector['name']);
        }
    }

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function sources(): array
    {
        return [
            'ticket' => ["1. a\n  - b\n", 2, 3, 'This list marker starts at column 2, below the content column 3 of the list item above, so it starts a separate list instead of nesting.'],
            'after a blank' => ["1. a\n\n  - b\n", 3, 3, 'This list marker starts at column 2 after a blank line, below the content column 3'],
            'crlf' => ["10. c\r\n   - d\r\n", 2, 4, 'below the content column 4'],
            'quoted' => ["> 10. c\n>    - d\n", 2, 6, 'below the content column 6'],
            'tab' => ["100. c\n\t- d\n", 2, 2, 'below the content column 5'],
            'innermost of an open item' => ["- x\n  9. a\n     - b\n  10. c\n     - d\n", 5, 6, 'below the content column 6'],
        ];
    }

    #[DataProvider('sources')]
    public function testTheMarkerIsReported(string $source, int $line, int $column, string $message): void
    {
        $warnings = $this->findings($source);
        self::assertCount(1, $warnings);
        self::assertSame([$line, $column], [$warnings[0]->line, $warnings[0]->column]);
        self::assertStringContainsString($message, $warnings[0]->message);
    }

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function findings(string $source): array
    {
        return array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'list-marker-below-content-column',
        ));
    }
}
