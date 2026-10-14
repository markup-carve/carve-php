<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotImageLabelNulsTest extends TestCase
{
    /**
     * @return list<array<string, string>>
     */
    private function images(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . str_replace("\0", '�', $html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $images = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $attributes = [];
            foreach ($image->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            ksort($attributes);
            $images[] = $attributes;
        }

        return $images;
    }

    public function testImageLabelsRestoreSourceNulsBeforeNestedChildParsing(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-image-label-nuls.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $converted = $converter->convert($row['source']);
            $this->assertSame($this->images($row['html']), $this->images($renderer->convert($converted)), $row['source']);
        }
        $this->assertSame('plain', $converter->convert('plain'));
    }
}
