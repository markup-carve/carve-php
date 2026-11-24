<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-A-154, C2-A-215: a body that `body-rows` splits spans its rows, a continued row without `pos` included.
 */
final class TableBodyPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, int, array{int, int}|null}>
     */
    public static function bodies(): array
    {
        return [
            'continued first row' => ["{body-rows=\"0,2\"}\n| x |\n+ z |\n| y |\n", 1, [18, 35]],
            'continued last row' => ["{body-rows=\"2\"}\n| < |\n| x |\n+ k |\n", 0, [16, 33]],
            'only a continued row' => ["{body-rows=\"1,0,1\"}\n| a |\n| b |\n+ c |\n", 2, [26, 37]],
            'empty body' => ["{body-rows=\"0,1\"}\n| a |\n", 0, null],
        ];
    }

    /**
     * @param string $source
     * @param int $body
     * @param array{int, int}|null $span
     */
    #[DataProvider('bodies')]
    public function testBodySpansItsRows(string $source, int $body, ?array $span): void
    {
        $tree = json_decode(Carve::encodeTree(Carve::parse($source)), true);
        $pos = $tree['children'][0]['children'][$body]['pos'] ?? null;

        self::assertSame($span, $pos === null ? null : [$pos['startOffset'], $pos['endOffset']]);
    }
}
