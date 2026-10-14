<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotDeepEscapedImagesTest extends TestCase
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

    private function text(string $html): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return trim(preg_replace('/[ \t\r\n]+/', ' ', $document->textContent) ?? '');
    }

    public function testDeepEscapedLabelsPreserveNativeValues(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-deep-escaped-images.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $converted = $converter->convert($row['source']);
            $html = $renderer->convert($converted);
            $this->assertSame($this->images($row['html']), $this->images($html), $row['source']);
            $this->assertSame($this->text($row['html']), $this->text($html), $row['source']);
        }
        foreach ([128, 512, 1600, 6400] as $depth) {
            foreach (['x\\[b\\]', '\\[x\\]', 'x\\\\y', 'x\\q', '\\]x\\['] as $leaf) {
                $source = str_repeat('![', $depth) . $leaf . str_repeat('](u)', $depth);
                $this->assertSame($renderer->convert($converter->convert('![' . $leaf . '](u)')), $renderer->convert($converter->convert($source)), $leaf);
            }
        }
    }
}
