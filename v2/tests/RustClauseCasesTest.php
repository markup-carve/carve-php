<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RustClauseCasesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $rows = file(__DIR__ . '/fixtures/rust-clause-cases.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(91, $rows);
        $cases = [];
        foreach ($rows as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $cases[$row['clause'] . ' ' . $row['caseId']] = [$row];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testC2RustClauseCase(array $case): void
    {
        $input = $case['input'];
        $options = $case['opts'];
        $this->assertIsString($input);
        $this->assertIsArray($options);
        $this->assertSame($case['expectedHtml'], Carve2::toHtml($input, $options));
        $tree = json_decode(Carve2::toJson($input, $options), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::sorted($case['expectedTree']), self::sorted($tree));
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
