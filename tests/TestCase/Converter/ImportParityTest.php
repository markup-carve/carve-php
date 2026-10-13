<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportParityTest extends TestCase
{
    #[DataProvider('cases')]
    public function testImportParity(string $format, string $source, string $html): void
    {
        $importer = $format === 'md' ? new MarkdownToCarve() : new DjotToCarve();
        self::assertSame(trim($html), trim((new CarveConverter())->convert($importer->convert($source))));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/fixtures/import-parity/*.json') ?: [] as $path) {
            $rows = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                yield $row['id'] => [$row['format'], $row['source'], $row['html']];
            }
        }
    }
}
