<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\ClauseHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentTransformTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function clauseCases(): array
    {
        $path = dirname(__DIR__, 2) . '/spec/conformance/clause-cases.json';
        $cases = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $wanted = ['CC-1221' => true, 'CC-1229' => true, 'CC-1230' => true];
        $out = [];
        foreach ($cases['cases'] ?? $cases as $case) {
            if (isset($wanted[$case['id']])) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('clauseCases')]
    public function testHostDocumentTransformsUseTheClauseCase(array $case): void
    {
        $transforms = ClauseHost::documentTransforms($case);
        $result = Carve::renderWithDocumentTransforms($case['tree'], $transforms);

        self::assertSame($case['expected']['html'], $result->html);
        $codes = array_map(static fn ($entry): string => $entry->code, $result->diagnostics);
        foreach ($case['expected']['diagnostics'] ?? [] as $code) {
            self::assertContains($code, $codes);
        }
    }
}
