<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class XrefCaptionCarriersTest extends TestCase
{
    /**
     * @param array{id:string,mode:string,html:string,projection:list<array<string,mixed>>,diagnostics:list<array<string,string>>} $row
     */
    #[DataProvider('cases')]
    public function testReferenceCarriers(array $row): void
    {
        $result = (new HtmlToCarve(importMode: $row['mode']))->convertToAstWithReport($row['html']);
        $projection = [];
        $visit = static function (array $node) use (&$visit, &$projection): void {
            if (in_array($node['type'] ?? null, ['heading_ref', 'caption_number', 'link', 'autolink', 'footnote_ref'], true)) {
                $projection[] = array_intersect_key($node, array_flip(['type', 'target', 'ref', 'href']));
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $visit($value);
                }
            }
        };
        $visit($result->value);
        self::assertEquals($row['projection'], $projection);
        self::assertSame($row['diagnostics'], array_values(array_map(
            static fn ($diagnostic): array => ['code' => $diagnostic->code, 'severity' => $diagnostic->severity],
            array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'attribute-dropped'),
        )));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $rows = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/xref-caption-carriers.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['id'] => [$row];
        }
    }
}
