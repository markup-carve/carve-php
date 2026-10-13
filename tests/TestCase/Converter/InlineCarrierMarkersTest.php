<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InlineCarrierMarkersTest extends TestCase
{
    /**
     * @param array{id:string, markdown:string, html:string, codes:list<string>, source?:string} $row
     */
    #[DataProvider('cases')]
    public function testReferenceMarkers(array $row): void
    {
        if (isset($row['source'])) {
            $writer = new CarveConverter(renderer: (new MarkdownRenderer())->setRoundTripMode(true));
            self::assertSame($row['markdown'], $writer->convert($row['source']));
        }
        $result = (new MarkdownToCarve())->convertWithFidelityReport($row['markdown']);
        $html = (new CarveConverter())->convert($result->value);
        self::assertSame(trim($row['html']), trim($html));
        self::assertSame($row['codes'], array_values(array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            array_filter($result->diagnostics, static fn ($diagnostic): bool => str_starts_with($diagnostic->code, 'carrier-')),
        )));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $rows = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/inline-carrier-markers.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['id'] => [$row];
        }
    }
}
