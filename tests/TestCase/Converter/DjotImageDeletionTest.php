<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotImageDeletionTest extends TestCase
{
    public function testDeletionContributesChildTextToImageAlternatives(): void
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/djot-image-deletion.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        foreach ($rows as $row) {
            $converter = new DjotToCarve();
            $result = $converter->convertWithFidelityReport($row['source']);
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">' . CarveConverter::create()->convert($converter->convert($row['source'])));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $image = $document->getElementsByTagName('img')->item(0);
            self::assertNotNull($image, $row['source']);
            $expected = $row['trailingLoss'] ? preg_replace('/[ \t]+\n/', "\n", $row['alt']) : $row['alt'];
            self::assertSame($expected, $image->getAttribute('alt'), $row['source']);
            $loss = array_filter($result->report()['diagnostics'], static fn (array $diagnostic): bool => str_contains($diagnostic['message'], 'trailing spaces before a line break'));
            self::assertSame($row['trailingLoss'], $loss !== [], $row['source']);
        }
    }
}
