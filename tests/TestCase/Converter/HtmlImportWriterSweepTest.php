<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shapes the one-writer refactor changed, pinned by their bytes.
 *
 * Routing HTML imports through the canonical writer (markup-carve/carve-php#2108)
 * changed 8,817 of 73,200 swept shapes while the whole suite stayed green,
 * because nothing covered any of them. These rows are the representative set the
 * ruling on markup-carve/carve-php#2169 asked for: the heading family the
 * refactor regressed, the table-cell rows that lost carve-rs agreement, the
 * caption rows whose escape is under a cross-engine question
 * (markup-carve/carve#2112), and a sample of the repairs.
 *
 * A row in one of those three families also pins what its Carve reads back to.
 * That is where the teeth are: both defects the ruling names wrote well-formed
 * Carve that says something else, `##` for a heading and `{++}` for an `<ins>`,
 * and only the readback can tell those from the bytes they should have been.
 */
class HtmlImportWriterSweepTest extends TestCase
{
    /**
     * @var array<string, int>
     */
    protected const GROUP_FLOOR = [
        'caption-hash' => 4,
        'cell-break' => 16,
        'heading-break' => 10,
        'repair' => 40,
    ];

    #[DataProvider('shapes')]
    public function testEveryPinnedShapeWritesItsBytes(string $id, string $html, string $carve, ?string $htmlBack): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html), $id);
    }

    #[DataProvider('shapes')]
    public function testEveryFamilyShapeReadsBackToThePinnedDocument(string $id, string $html, string $carve, ?string $htmlBack): void
    {
        if ($htmlBack === null) {
            $this->markTestSkipped($id . ' is a sampled repair, pinned by its bytes alone');
        }

        $this->assertSame($htmlBack, (new CarveConverter())->convert($carve), $id);
    }

    public function testTheFixtureCarriesEveryGroup(): void
    {
        $counts = [];
        foreach (self::rows() as $row) {
            $counts[$row['group']] = ($counts[$row['group']] ?? 0) + 1;
        }
        ksort($counts);

        $this->assertSame(array_keys(self::GROUP_FLOOR), array_keys($counts));
        foreach (self::GROUP_FLOOR as $group => $floor) {
            $this->assertGreaterThanOrEqual($floor, $counts[$group], $group);
        }
    }

    /**
     * Every family row carries a readback, so the pin above cannot be satisfied
     * by bytes nobody looked at.
     */
    public function testEveryFamilyRowPinsItsReadback(): void
    {
        foreach (self::rows() as $row) {
            if ($row['group'] === 'repair') {
                continue;
            }
            $this->assertArrayHasKey('htmlBack', $row, $row['id']);
            $this->assertNotSame('', $row['htmlBack'], $row['id']);
        }
    }

    /**
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function shapes(): array
    {
        $cases = [];
        foreach (self::rows() as $row) {
            $cases[$row['id']] = [$row['id'], $row['html'], $row['carve'], $row['htmlBack'] ?? null];
        }

        return $cases;
    }

    /**
     * @return array<int, array{id: string, group: string, html: string, carve: string, htmlBack?: string}>
     */
    protected static function rows(): array
    {
        $path = dirname(__DIR__, 3) . '/tests/fixtures/html-import-writer-sweep.json';

        return json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
