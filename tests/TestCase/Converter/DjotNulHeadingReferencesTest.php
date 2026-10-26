<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotNulHeadingReferencesTest extends TestCase
{
    /**
     * @return array<int, array<string, string>>
     */
    private function elements(string $html): array
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
        foreach ($document->getElementsByTagName('*') as $image) {
            if (in_array($image->nodeName, ['html', 'head', 'body', 'tbody'], true)) {
                continue;
            }
            $depth = 0;
            for ($parent = $image->parentNode; $parent !== null; $parent = $parent->parentNode) {
                if ($parent->nodeName !== 'tbody') {
                    $depth++;
                }
            }
            $attributes = ['#element' => $image->nodeName, '#depth' => (string)$depth];
            foreach ($image->attributes as $attribute) {
                if (in_array($attribute->name, ['data-delim', 'aria-label'], true)) {
                    continue;
                }
                $attributes[$attribute->name] = in_array($attribute->name, ['href', 'src'], true)
                    ? str_replace(' ', '%20', $attribute->value) : $attribute->value;
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
            $document->loadHTML('<?xml encoding="UTF-8">' . str_replace("\0", '�', $html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return trim(preg_replace('/[ \t\r\n]+/', ' ', $document->textContent) ?? '');
    }

    public function testNulHeadingReferencesPreserveNativeValues(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-nul-heading-references.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $converted = $converter->convert($row['source']);
            $html = $renderer->convert($converted);
            $expectedElements = $this->elements(ImageParagraphFixture::normalized($row['html']));
            $actualElements = $this->elements($html);
            if ($row['destinationOnly'] ?? false) {
                $images = static function (array $elements): array {
                    $values = [];
                    foreach ($elements as $element) {
                        if (isset($element['href']) || isset($element['src'])) {
                            $values[] = array_intersect_key($element, array_flip(['href', 'src']));
                        }
                    }

                    return $values;
                };
                $expectedElements = $images($expectedElements);
                $actualElements = $images($actualElements);
            }
            $this->assertSame($expectedElements, $actualElements, $row['source']);
            $this->assertSame($this->text($row['html']), $this->text($html), $row['source']);
        }
    }

    public function testReferenceTargetsTheAllocatedHeadingId(): void
    {
        $sources = [
            "# H\0\n\n[H\0][]\n\n{#H�}\n{.x}",
            "# A�\n\n# A\0\n\n[x][A\0]",
            "# A b\0\n\n# A-b\0\n\n[x][A-b\0]",
        ];
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($sources as $source) {
            $elements = $this->elements($renderer->convert($converter->convert($source)));
            $ids = [];
            $destinations = [];
            foreach ($elements as $element) {
                if ($element['#element'] === 'section' && isset($element['id'])) {
                    $ids[] = $element['id'];
                }
                if (isset($element['href'])) {
                    $destinations[] = $element['href'];
                }
            }
            $this->assertCount(1, $destinations, $source);
            $this->assertSame('#' . $ids[count($ids) - 1], $destinations[0], $source);
        }
    }
}
