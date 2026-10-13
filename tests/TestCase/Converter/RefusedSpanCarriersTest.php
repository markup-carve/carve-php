<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RefusedSpanCarriersTest extends TestCase
{
    /** @param array{id:string,mode:string,html:string,source:string,diagnostics:list<array{string,string,string}>} $row */
    #[DataProvider('cases')]
    public function testReferenceFallbackAndReports(array $row): void
    {
        $result = (new HtmlToCarve(importMode: $row['mode']))->convertWithReport($row['html']);
        self::assertSame($row['source'], $result->value);
        $diagnostics = array_map(static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->severity, $diagnostic->path], $result->diagnostics);
        sort($diagnostics);
        self::assertSame($row['diagnostics'], $diagnostics);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function cases(): iterable
    {
        $rows = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/refused-span-carriers.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['id'] => [$row];
        }
    }
}
