<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-066, C2-W-136 as ruled on carve#6860: a pipe table writes `header-rows` wherever the written marks and keys give
 * another head, and makes no body header keys beside a `body-rows` that `attrs` keeps.
 */
final class PipeTableHeadCountTest extends TestCase
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
        $ids = ['CC-2070', 'CC-2242', 'CC-2249', 'CC-2468', 'CC-2469', 'CC-2470', 'CC-2471'];
        $cases = [];
        foreach ($data['cases'] as $case) {
            if (in_array($case['id'], $ids, true)) {
                $cases[$case['id']] = [$case['input'], $case['expected']['carve']];
            }
        }
        self::assertCount(count($ids), $cases);

        return $cases;
    }

    #[DataProvider('clauseCases')]
    public function testClauseCaseWritesWithoutReport(string $input, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($input);

        self::assertSame($expected, $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readBackShapes(): array
    {
        return [
            'header rows above an empty body and a header foot' => ["{header-rows=2 footer-rows=1 body-rows=0}\n|= a |\n|= b |\n|= c |\n"],
            'kept body-rows above a body of two header rows' => ["{header-rows=0 body-rows=x}\n|= a |\n|= b |\n| 1 |\n"],
            'kept body-rows with a head of a data cell' => ["{header-rows=1 body-rows=x}\n| a |\n|= b |\n"],
            'kept empty body-rows with a foot count' => [
                "{header-rows=1 footer-rows=1 body-rows body-header-rows=1}\n|= a |\n|= b |\n|= c | d |\n",
            ],
            'made body header rows keep the head' => [
                "{header-rows=1 body-rows=1 body-header-rows=1 footer-rows=1}\n|= h |\n|= a |\n| 1 |\n|= f |\n",
            ],
        ];
    }

    #[DataProvider('readBackShapes')]
    public function testWrittenSourceReadsBackToTheTree(string $source): void
    {
        $result = Carve::formatWithDiagnostics($source);

        self::assertSame([], $result->diagnostics);
        self::assertSame(self::shape(Carve::parse($source)), self::shape(Carve::parse($result->source)));
    }

    /**
     * @return mixed
     */
    private static function shape(Node $tree): mixed
    {
        $strip = static function (mixed $value) use (&$strip): mixed {
            if (!is_array($value)) {
                return $value;
            }
            unset($value['pos'], $value['srcByteLength']);

            return array_map($strip, $value);
        };

        return $strip(json_decode(Carve::encodeTree($tree), true, 4096, JSON_THROW_ON_ERROR));
    }
}
