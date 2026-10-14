<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotEmptyDefinitionsTest extends TestCase
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
                if (in_array($attribute->name, ['data-delim', 'aria-label', 'data-task-state'], true)) {
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

        return trim(preg_replace('/[ \t\r\n]+/', ' ', str_replace('↩︎', '↩', $document->textContent)) ?? '');
    }

    public function testEmptyDefinitionsPreserveNativeStructure(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-empty-definitions.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $result = $converter->convertWithFidelityReport($row['source']);
            $converted = $result->value;
            foreach ($result->report()['diagnostics'] as $diagnostic) {
                self::assertSame(0, preg_match('/empty definition|definition item with an empty term/i', $diagnostic['message']), $row['source']);
            }
            $html = $renderer->convert($converted);
            $expectedElements = $this->elements($row['html']);
            $actualElements = $this->elements($html);
            $this->assertSame($expectedElements, $actualElements, $row['source']);
            $this->assertSame($this->text($row['html']), $this->text($html), $row['source']);
        }
    }

    public function testCarriersPreserveAuthoredPlaceholderText(): void
    {
        $source = ": \0DJOTEMPTYTERM\0";
        $converted = (new DjotToCarve())->convert($source);
        self::assertStringContainsString("\0DJOTEMPTYTERM\0", $converted);
        self::assertStringContainsString('<dd></dd>', (new CarveConverter())->convert($converted));
    }

    public function testCodeFallbackReportsOriginalContinuationOpener(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("before\n\n: term\n  `code\n  : x`{.x}\n\n  body");
        $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => $row['code'] === 'structure-unspellable'));
        self::assertSame('line:4', $losses[0]['path']);
        self::assertSame('Multiline inline code uses raw HTML; plain-text export omits it.', $losses[0]['message']);
    }
}
