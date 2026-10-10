<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownHtmlCodePayloadTest extends TestCase
{
    public function testImportedCodePayloadsAndContainers(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/markdown-html-code-payloads.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([false, true] as $convertRawHtml) {
            foreach ($cases as $case) {
                $result = (new MarkdownToCarve(convertRawHtml: $convertRawHtml))->convertWithFidelityReport($case['markdown']);
                $html = (new CarveConverter())->convert($result->value);
                $expected = [['value' => $case['value'], 'ancestors' => array_values(array_diff($case['ancestors'], ['tbody'])), 'elements' => []]];
                $this->assertSame($expected, $this->records($html)['codes'], $case['template'] . ': ' . json_encode($case['value']));
                $fallback = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback'));
                $this->assertCount($case['value'] === '' || strpbrk($case['value'], "\r\n") !== false ? 1 : 0, $fallback);
                foreach ($fallback as $row) {
                    $this->assertSame('degraded', $row->fidelity);
                    $this->assertSame('exact', $row->confidence);
                    $this->assertSame('warning', $row->severity);
                }
            }
        }
    }

    public function testNativeCodeControlsAndSurroundingText(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/markdown-html-code-controls.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([false, true] as $convertRawHtml) {
            foreach ($cases as $case) {
                if ($convertRawHtml && $case['codes'] === []) {
                    continue;
                }
                $source = (new MarkdownToCarve(convertRawHtml: $convertRawHtml))->convert($case['markdown']);
                $html = (new CarveConverter())->convert($source);
                if (str_starts_with($case['markdown'], '<?')) {
                    // libxml treats processing instructions differently from HTML5.
                    $this->assertSame(rtrim($case['nativeHtml'], "\n"), rtrim($html, "\n"));
                } else {
                    $this->assertSame($this->records($case['nativeHtml']), $this->records($html), ($convertRawHtml ? 'converted: ' : 'verbatim: ') . $case['markdown']);
                }
            }
        }
    }

    private function records(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            $this->markTestSkipped('DOM is required for native HTML readback');
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . str_replace(["\r\n", "\r"], "\n", $html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $codes = [];
        $roots = [];
        $visit = function (DOMNode $node, array $ancestors = []) use (&$visit, &$codes, &$roots): void {
            $next = $ancestors;
            // libxml omits the table body that HTML5 readers insert.
            if ($node instanceof DOMElement && !in_array($node->tagName, ['html', 'head', 'body', 'section', 'tbody'], true)) {
                $next[] = $node->tagName;
            }
            if ($node instanceof DOMElement && $node->tagName === 'code') {
                $elements = [];
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $elements[] = $child->tagName;
                    }
                }
                $codes[] = ['value' => $node->textContent, 'ancestors' => $next, 'elements' => $elements];
            }
            if ($node instanceof DOMElement && in_array($node->tagName, ['p', 'h1', 'h2', 'td', 'th'], true)) {
                $roots[] = ['tag' => $node->tagName, 'value' => $node->textContent];
            }
            foreach ($node->childNodes as $child) {
                $visit($child, $next);
            }
        };
        $visit($dom);

        return ['codes' => $codes, 'roots' => $roots];
    }
}
