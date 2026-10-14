<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotGenericDivClassesTest extends TestCase
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

    public function testGenericDivClassesPreserveNativeStructure(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-generic-div-classes.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $converted = $converter->convert($row['source']);
            $html = $renderer->convert($converted);
            $expectedElements = $this->elements($row['html']);
            $actualElements = $this->elements($html);
            $this->assertSame($expectedElements, $actualElements, $row['source']);
            $this->assertSame($this->text($row['html']), $this->text($html), $row['source']);
        }
    }

    public function testCanonicalClassesReportTheirGenericWrapperLoss(): void
    {
        foreach (['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'] as $kind) {
            $source = '::: ' . $kind . "\nbody\n:::";
            $diagnostics = (new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'];
            $losses = array_filter($diagnostics, static fn (array $row): bool => $row['code'] === 'structure-unspellable'
                && str_contains($row['message'], 'generic DIV wrapper'));
            self::assertCount(1, $losses, $kind);
            self::assertSame('line:1', array_values($losses)[0]['path']);
        }
        foreach (["::: a\n::: note\nbody\n:::", "{.x}\n::: note\nbody\n:::", "> a\n::: note\nbody\n:::"] as $source) {
            $diagnostics = (new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'];
            self::assertNotEmpty(array_filter($diagnostics, static fn (array $row): bool => str_contains($row['message'], 'generic DIV wrapper')), $source);
        }
        foreach (["```\n::: note\n```", "paragraph\n::: note", '`::: note`'] as $source) {
            $diagnostics = (new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'];
            self::assertSame([], array_values(array_filter($diagnostics, static fn (array $row): bool => str_contains($row['message'], 'generic DIV wrapper'))), $source);
        }
    }
}
