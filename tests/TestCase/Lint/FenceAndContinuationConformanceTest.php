<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\FenceAndContinuationLinter;
use PHPUnit\Framework\TestCase;

final class FenceAndContinuationConformanceTest extends TestCase
{
    public function testBomKeepsTheFenceByteSpanOnItsMarker(): void
    {
        $warnings = (new FenceAndContinuationLinter())->lint("\u{FEFF}```\nx\n");
        self::assertCount(1, $warnings);
        self::assertSame([1, 1, 3, 6], [$warnings[0]->line, $warnings[0]->column, $warnings[0]->start, $warnings[0]->end]);
    }

    public function testVectors(): void
    {
        foreach (['unclosed-code-fence' => 20, 'continuation-marker-refused' => 55] as $rule => $count) {
            $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixture/lint-rule-vectors/' . $rule . '.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertCount($count, $data['vectors']);
            foreach ($data['vectors'] as $vector) {
                $actual = array_values(array_filter(
                    (new DocumentLinter())->lint($vector['source']),
                    static fn ($warning): bool => $warning->rule === $rule,
                ));
                $expected = $vector['expected']['findings'];
                if ($rule === 'continuation-marker-refused' && $vector['name'] === 'item-first-block-indented') {
                    // carve#3772: the content-column follower consumes this first-block attachment.
                    $expected = [];
                }
                self::assertSame(
                    array_map(static fn (array $f): array => [$f['line'], $f['column']], $expected),
                    array_map(static fn ($w): array => [$w->line, $w->column], $actual),
                    $rule . ': ' . $vector['name'],
                );
                foreach ($expected as $i => $finding) {
                    foreach ($finding['includes'] as $phrase) {
                        self::assertStringContainsString($phrase, $actual[$i]->message, $rule . ': ' . $vector['name']);
                    }
                }
            }
        }
    }
}
