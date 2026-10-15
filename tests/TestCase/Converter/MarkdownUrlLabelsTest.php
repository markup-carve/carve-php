<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownUrlLabelsTest extends TestCase
{
    public static function controls(): iterable
    {
        $controls = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-url-labels.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($controls as $index => $control) {
            yield 'native case ' . $index => [$control];
        }
    }

    #[DataProvider('controls')]
    public function testMarkdownUrlLabelsKeepTheirTextAndEmphasis(array $control): void
    {
        $converter = new CarveConverter();
        $source = (new MarkdownToCarve())->convert($control['source']);
        $migration = (new MarkdownToCarve())->convertWithFidelityReport($control['source']);
        $this->assertSame($source, $migration->value);
        $this->assertNotSame([], $migration->diagnostics);
        if (array_filter($migration->diagnostics, static fn ($row): bool => $row->confidence !== 'exact') !== []) {
            $this->assertContains('fidelity-unverified', array_map(static fn ($row): string => $row->code, $migration->diagnostics));
        }
        foreach ($migration->diagnostics as $diagnostic) {
            $this->assertFalse($diagnostic->confidence === 'exact' && $diagnostic->fidelity === 'dropped');
            if ($diagnostic->code === 'fidelity-unverified') {
                $this->assertSame('warning', $diagnostic->severity);
                $this->assertSame('dropped', $diagnostic->fidelity);
                $this->assertSame('fallback', $diagnostic->confidence);
            }
        }
        $document = $converter->parse($source);
        $written = (new CarveRenderer())->render($document);
        foreach ([$converter->convert($source), $converter->render($document), $converter->convert($written)] as $html) {
            $this->assertSame($this->htmlTree($control['html']), $this->htmlTree($html), $control['source']);
        }
    }

    public function testLiteralPathSpellingIsUnchanged(): void
    {
        foreach (["[a/b](x)\n", "[src/index.ts](x)\n", "[https://e.test](https://e.test)\n"] as $source) {
            $this->assertSame($source, (new MarkdownToCarve())->convert($source));
        }
    }

    public function testBareUrlNormalizationHasExactFidelity(): void
    {
        foreach (["https://x.test/[r]\n\n[r]: /u\n", "https://x.test/a\\_b/[r]\n\n[r]: /u\n"] as $source) {
            $rows = (new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics;
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertSame('exact', $row->confidence);
                $this->assertNotSame('dropped', $row->fidelity);
            }
        }
    }

    public function testSupportedUrlLabelsAreVerifiedExactly(): void
    {
        foreach (["[https://e.test](x)\n", "[*https://e.test*](x)\n", "[https://x.test/_a_](x)\n", "[a/b](x)\n", "[src/index.ts](x)\n", "[*https://example.test/a/_v1_/x*](u)\n"] as $source) {
            $diagnostics = (new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics;
            $this->assertContains('markdown-link', array_map(static fn ($row): string => $row->code, $diagnostics));
            foreach ($diagnostics as $diagnostic) {
                $this->assertSame('exact', $diagnostic->confidence);
                $this->assertNotSame('dropped', $diagnostic->fidelity);
            }
        }
    }

    public function testTitledEmptyDestinationsDoNotResolveAnotherReference(): void
    {
        foreach (
            [
                ["[r](<> \"t\")\n\n[r]: /u\n", "[r]( \"t\")\n\n[r]: /u\n"],
                ["[https://x.test](<> \"t\")\n\n[https://x.test]: /u\n", "[https://x.test]( \"t\")\n\n[https://x.test]: /u\n"],
            ] as [$source, $expected]
        ) {
            $migration = (new MarkdownToCarve())->convertWithFidelityReport($source);
            $this->assertSame($expected, $migration->value);
            $this->assertSame([], array_values(array_filter($migration->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable' && $row->fidelity === 'dropped')));
        }
    }

    private function htmlTree(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->domTree($dom->getElementsByTagName('body')->item(0));
    }

    private function domTree(?DOMNode $node, bool $literal = false): array
    {
        if ($node instanceof DOMText) {
            $parent = $node->parentNode;
            $container = $parent instanceof DOMElement && in_array($parent->tagName, ['body', 'blockquote', 'ul', 'ol', 'dl', 'table', 'thead', 'tbody', 'tfoot', 'tr'], true);

            return !$literal && $container && trim($node->data) === '' ? [] : ['text' => $literal ? $node->data : preg_replace('/[\t\n\r\f ]+/', ' ', $node->data)];
        }
        if (!$node instanceof DOMElement) {
            return [];
        }
        $literal = $literal || in_array($node->tagName, ['pre', 'code'], true);
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->name] = in_array($attribute->name, ['checked', 'disabled'], true) ? true : $attribute->value;
        }
        ksort($attributes);
        $children = [];
        foreach ($node->childNodes as $child) {
            $tree = $this->domTree($child, $literal);
            if ($tree !== []) {
                $children[] = $tree;
            }
        }
        if (!$literal && in_array($node->tagName, ['p', 'li', 'dt', 'dd', 'blockquote'], true) && $children !== []) {
            $first = array_key_first($children);
            $last = array_key_last($children);
            if (isset($children[$first]['text'])) {
                $children[$first]['text'] = ltrim($children[$first]['text']);
            }
            if (isset($children[$last]['text'])) {
                $children[$last]['text'] = rtrim($children[$last]['text']);
            }
        }

        return ['tag' => $node->tagName, 'attributes' => $attributes, 'children' => $children];
    }
}
