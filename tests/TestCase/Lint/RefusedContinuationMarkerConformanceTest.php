<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\TestCase;

final class RefusedContinuationMarkerConformanceTest extends TestCase
{
    public function testSharedVectors(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixture/lint-rule-vectors/refused-continuation-marker.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(24, $data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $actual = array_values(array_filter(
                (new DocumentLinter())->lint($vector['source']),
                static fn ($warning): bool => $warning->rule === 'refused-continuation-marker',
            ));
            $expected = $vector['expected']['findings'];
            self::assertSame(
                array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
                array_map(static fn ($warning): array => [$warning->line, $warning->column], $actual),
                $vector['name'],
            );
            foreach ($expected as $index => $finding) {
                foreach ($finding['includes'] as $phrase) {
                    self::assertStringContainsString($phrase, $actual[$index]->message, $vector['name']);
                }
            }
        }
    }
}
