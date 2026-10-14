<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotImageAltLossesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function alternatives(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $values = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $values[] = $image->getAttribute('alt');
        }

        return $values;
    }

    public function testLossesUseRenderedChildTextAndOriginalSourceLines(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-image-alt-losses.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $result = $converter->convertWithFidelityReport($row['source']);
            $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $item): bool => $item['code'] === 'structure-unspellable' && str_starts_with($item['message'], 'An image alternative text')));
            self::assertCount($row['kind'] === 'none' ? 0 : 1, $losses, $row['source']);
            $actual = $this->alternatives($renderer->convert($result->value));
            if ($row['kind'] === 'none') {
                self::assertSame([$row['alt']], $actual, $row['source']);
            } else {
                self::assertSame('line:' . $row['line'], $losses[0]['path'], $row['source']);
                self::assertSame('exact', $losses[0]['confidence'], $row['source']);
                self::assertStringContainsString($row['kind'] === 'blank' ? 'blank line' : 'trailing spaces', $losses[0]['message'], $row['source']);
                self::assertNotSame([$row['alt']], $actual, $row['source']);
            }
        }
    }
}
