<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use DOMDocument;
use DOMElement;
use DOMNode;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownNativeDestinationsTest extends TestCase
{
    public function testSharedNativeDestinationControls(): void
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/fixtures/markdown-native-destinations.json'), true, 512, JSON_THROW_ON_ERROR);
        $importer = new MarkdownToCarve();
        $renderer = new CarveConverter();
        foreach ($rows as $index => $row) {
            $written = $importer->convert($row['source']);
            $actual = self::shape($renderer->convert($written));
            $expected = self::shape($row['carveHtml'] ?? $row['html']);
            self::assertSame($expected, $actual, 'row ' . $index . ': ' . $row['source']);
        }
    }

    public function testEntityReferenceLabelsKeepTheirAuthoredSpelling(): void
    {
        $importer = new MarkdownToCarve();
        $renderer = new CarveConverter();
        $written = $importer->convert("[&auml;]\n\n[ä]: /u\n");
        self::assertSame("[ä]\n\n[ä]: /u\n", $written);
        self::assertSame(preg_replace('/\n\z/', '', "<p>[ä]</p>\n"), $renderer->convert($written));

        $sameSpelling = $importer->convert("[&auml;]\n\n[&auml;]: /u\n");
        self::assertStringContainsString('<a href="/u">ä</a>', $renderer->convert($sameSpelling));
    }

    public function testFootnoteAfterBracketedTextRemainsAReference(): void
    {
        $written = (new MarkdownToCarve())->convert("[x][^1]\n\n[^1]: /u\n");
        $html = (new CarveConverter())->convert($written);
        self::assertStringStartsWith('\\[x][^1]', $written);
        self::assertStringContainsString('[x]<a id="fnref1"', $html);
        self::assertStringContainsString('<p>/u<a href="#fnref1"', $html);
    }

    private static function shape(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        self::assertNotNull($body);

        return self::children($body);
    }

    private static function children(DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/[\t\n\r\f ]+/', ' ', $child->nodeValue ?? '') ?? '';
                if (in_array($node->nodeName, ['body', 'blockquote', 'ul', 'ol', 'table', 'thead', 'tbody', 'tr'], true) && trim($text) === '') {
                    continue;
                }
                $children[] = $text;

                continue;
            }
            $descendants = self::children($child);
            if ($child->nodeName === 'p' && $child->childNodes->length === 1 && $child->firstChild?->nodeName === 'img') {
                $children[] = $descendants[0];

                continue;
            }
            $attributes = [];
            if ($child instanceof DOMElement) {
                foreach ($child->attributes as $attribute) {
                    if ($attribute->name === 'scope' && $attribute->value === 'col') {
                        continue;
                    }
                    $value = $attribute->value;
                    if (in_array($attribute->name, ['href', 'src'], true) && str_starts_with($value, '/')) {
                        $value = preg_replace(['/\%5b/i', '/\%5d/i'], ['[', ']'], $value) ?? $value;
                    }
                    $attributes[$attribute->name] = $value;
                }
                ksort($attributes);
            }
            $children[] = [$child->nodeName, $attributes, $descendants];
        }

        return $children;
    }
}
