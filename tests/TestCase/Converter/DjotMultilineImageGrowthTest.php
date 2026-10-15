<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotMultilineImageGrowthTest extends TestCase
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
        $elements = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array($element->nodeName, ['html', 'head', 'body', 'tbody'], true)) {
                continue;
            }
            $depth = 0;
            for ($parent = $element->parentNode; $parent !== null; $parent = $parent->parentNode) {
                if ($parent->nodeName !== 'tbody') {
                    $depth++;
                }
            }
            $attributes = ['#element' => $element->nodeName, '#depth' => (string)$depth];
            foreach ($element->attributes as $attribute) {
                if (in_array($attribute->name, ['data-delim', 'aria-label', 'data-task-state'], true) || $attribute->name === 'role' && $attribute->value === 'math' || $attribute->name === 'scope' && $attribute->value === 'col') {
                    continue;
                }
                if ($element->nodeName === 'input' && in_array($attribute->name, ['disabled', 'checked'], true)) {
                    $attributes[$attribute->name] = '';

                    continue;
                }
                $attributes[$attribute->name] = in_array($attribute->name, ['href', 'src'], true)
                    ? str_replace(' ', '%20', $attribute->value) : $attribute->value;
            }
            ksort($attributes);
            $elements[] = $attributes;
        }

        return $elements;
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

        foreach (['td', 'th'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $cell) {
                $cell->appendChild($document->createTextNode(' '));
            }
        }

        return trim(preg_replace('/[ \t\r\n]+/', ' ', str_replace('↩︎', '↩', $document->textContent)) ?? '');
    }

    public function testNativeOutputAndLossGuards(): void
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/djot-multiline-image-growth.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $converter = new DjotToCarve();
            $result = $converter->convertWithFidelityReport($row['source']);
            $html = CarveConverter::create()->convert($result->value);
            self::assertSame($this->elements($row['html']), $this->elements($html), $row['source']);
            self::assertSame($this->text($row['html']), $this->text($html), $row['source']);
            $losses = array_values(array_map(static fn (array $d): string => $d['message'], array_filter($result->report()['diagnostics'], static fn (array $d): bool => $d['code'] === 'structure-unspellable')));
            self::assertSame($row['losses'], $losses, $row['source']);
        }
    }

    public function testDeepChainsPreserveAllAlternativeText(): void
    {
        foreach ([256, 512] as $depth) {
            $converter = new DjotToCarve();
            $result = $converter->convertWithFidelityReport(str_repeat("![x\n", $depth) . 'z' . str_repeat('](u)', $depth));
            $html = CarveConverter::create()->convert($result->value);
            $elements = $this->elements($html);
            $images = array_values(array_filter($elements, static fn (array $a): bool => $a['#element'] === 'img'));
            self::assertCount(1, $images);
            self::assertSame(str_repeat("x\n", $depth) . 'z', $images[0]['alt']);
            self::assertSame([], array_values(array_filter($result->report()['diagnostics'], static fn (array $d): bool => $d['code'] === 'structure-unspellable')));
        }
    }

    public function testSiteFrontmatterIsPreserved(): void
    {
        $header = "---\nalt: ![a\n  ![b](u)](v)\n---";
        self::assertStringStartsWith($header, (new DjotToCarve())->convert($header . "\n\ntext"));
    }
}
