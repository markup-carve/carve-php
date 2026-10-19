<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\TestCase;

final class ConsumedKeyNearMissConformanceTest extends TestCase
{
    public function testVectors(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/lint-rule-vectors/consumed-key-near-miss.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(37, $data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $actual = array_values(array_filter(
                (new DocumentLinter())->lint($vector['source']),
                static fn ($warning): bool => $warning->rule === $data['rule'],
            ));
            $expected = $vector['expected']['findings'];
            self::assertSame(
                array_map(static fn (array $f): array => [$f['line'], $f['column']], $expected),
                array_map(static fn ($w): array => [$w->line, $w->column], $actual),
                $vector['name'],
            );
            foreach ($expected as $i => $finding) {
                self::assertStringContainsString($finding['word'], $actual[$i]->message, $vector['name']);
                self::assertStringContainsString('{' . $finding['suggests'] . '}', $actual[$i]->message, $vector['name']);
            }
        }
    }
}
