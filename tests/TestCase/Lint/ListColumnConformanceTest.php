<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class ListColumnConformanceTest extends TestCase
{
    use RuleATodo;

    public function testSharedVectors(): void
    {
        foreach (['list-marker-below-content-column', 'ordered-list-split-by-indent'] as $rule) {
            $path = __DIR__ . '/../../Fixture/lint-rule-vectors/' . $rule . '.json';
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($rule, $data['rule']);
            foreach ($data['vectors'] as $vector) {
                $actual = array_values(array_filter(
                    (new DocumentLinter())->lint($vector['source']),
                    static fn ($warning): bool => $warning->rule === $rule,
                ));
                $expected = $vector['expected']['findings'];
                self::assertSame(
                    array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
                    array_map(static fn ($warning): array => [$warning->line, $warning->column], $actual),
                    $vector['name'],
                );
                foreach ($expected as $index => $finding) {
                    foreach ($finding['includes'] as $fragment) {
                        self::assertStringContainsString($fragment, $actual[$index]->message, $vector['name']);
                    }
                }
            }
        }
    }

    public function testSourceCoordinatesAndNestedContainers(): void
    {
        $cases = [
            ["10. c\r\n   - d\r\n", 'list-marker-below-content-column', 2, 4],
            ["> 10. c\n>    - d\n", 'list-marker-below-content-column', 2, 6],
            ["100. c\n\t- d\n", 'list-marker-below-content-column', 2, 2],
            ["é\n\n10. c\n   - d\n", 'list-marker-below-content-column', 4, 4],
            ["10. c\r\n\r\n   - d\r\n", 'list-marker-below-content-column', 3, 4],
            ["> 10. c\n>\n>    - d\n", 'list-marker-below-content-column', 3, 6],
            ["100. c\n\n\t- d\n", 'list-marker-below-content-column', 3, 2],
            ["é\n\n10. c\n\n   - d\n", 'list-marker-below-content-column', 5, 4],
            ["- x\n  9. a\n     - b\n  10. c\n     - d\n", 'list-marker-below-content-column', 5, 6],
            [" 9. nine\r\n10. ten\r\n", 'ordered-list-split-by-indent', 2, 1],
            [">  9. nine\n> 10. ten\n", 'ordered-list-split-by-indent', 2, 3],
        ];
        foreach ($cases as [$source, $rule, $line, $column]) {
            $warnings = array_values(array_filter(
                (new DocumentLinter())->lint($source),
                static fn ($warning): bool => $warning->rule === $rule,
            ));
            self::assertCount(1, $warnings, $source);
            self::assertSame([$line, $column], [$warnings[0]->line, $warnings[0]->column], $source);
        }
    }
}
