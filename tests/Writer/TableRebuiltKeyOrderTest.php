<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableRebuiltKeyOrderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function clauseCases(): array
    {
        $data = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $cases = [];
        foreach ($data['cases'] as $case) {
            if (in_array($case['id'], ['CC-1699', 'CC-1700', 'CC-1701', 'CC-1702', 'CC-1703', 'CC-1704'], true)) {
                $cases[$case['id']] = [$case['input'], $case['expected']['carve']];
            }
        }
        self::assertCount(6, $cases);

        return $cases;
    }

    #[DataProvider('clauseCases')]
    public function testRebuiltKeysFollowRetainedKeysInClauseOrder(string $input, string $expected): void
    {
        self::assertSame($expected, Carve::format($input));
    }
}
