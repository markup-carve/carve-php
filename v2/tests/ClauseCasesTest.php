<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClauseCasesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $file = __DIR__ . '/fixtures/clause-cases.json';
        $rows = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        $cases = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['clause'] ?? null);
            self::assertIsString($row['case'] ?? null);
            $key = $row['clause'] . ' ' . $row['case'];
            self::assertArrayNotHasKey($key, $cases);
            $cases[$key] = [$row];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testC2ClauseCase(array $case): void
    {
        $source = $case['input'];
        $options = $case['options'];
        $this->assertIsString($source);
        $this->assertIsArray($options);
        $this->assertSame($case['html'], Carve2::toHtml($source, $options));
        $tree = json_decode(Carve2::toJson($source, Corpus::parseOptions($options)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::stripFacts($case['tree']), self::stripFacts($tree));
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function stripFacts(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::stripFacts(...), $value);
        }
        if (is_string($value['type'] ?? null)) {
            unset($value['pos'], $value['stages'], $value['srcByteLength'], $value['astVersion']);
        }
        foreach ($value as &$child) {
            $child = self::stripFacts($child);
        }
        ksort($value, SORT_STRING);

        return $value;
    }
}
