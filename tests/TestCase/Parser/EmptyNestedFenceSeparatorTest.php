<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmptyNestedFenceSeparatorTest extends TestCase
{
    public static function controls(): iterable
    {
        $controls = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/empty-nested-marker-fences.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($controls as $index => $control) {
            yield 'native case ' . $index => [$control];
        }
    }

    #[DataProvider('controls')]
    public function testParentSeparatorsStayOutsideEmptyNestedFences(array $control): void
    {
        $converter = new CarveConverter();
        $source = $control['source'];
        // Source-layout parsing enables positions through the public converter API.
        $converter->parseWithSourceLayout($source);
        $document = $converter->parse($source);
        $expected = array_column($control['codes'], 'value');
        $this->assertSame($expected, $this->codePayloads($document, $control['empty'] ?? true), $source);
        $written = (new CarveRenderer())->render($document);
        $writtenDocument = $converter->parse($written);
        $this->assertSame($expected, $this->codePayloads($writtenDocument), $source);
        $this->assertSame($this->rawPayloads($document), $this->rawPayloads($writtenDocument), $source);
        foreach ([$converter->convert($source), $converter->render($document), $converter->convert($written)] as $html) {
            $this->assertSame($this->htmlTree($control['html']), $this->htmlTree($html), $source);
            $this->assertSame(count($expected), substr_count($html, '</code>'), $source);
            if ($control['empty'] ?? true) {
                $this->assertStringNotContainsString('<code>' . "\n" . '</code>', $html, $source);
            }
        }
    }

    private function rawPayloads(Node $node): array
    {
        if ($node instanceof RawBlock) {
            return [$node->getContent()];
        }
        $result = [];
        foreach ($node->getChildren() as $child) {
            array_push($result, ...$this->rawPayloads($child));
        }

        return $result;
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
            return !$literal && trim($node->data) === '' ? [] : ['text' => $node->data];
        }
        if (!$node instanceof DOMElement) {
            return [];
        }
        $literal = $literal || in_array($node->tagName, ['pre', 'code'], true);
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            if (in_array($node->tagName, ['ul', 'li', 'input'], true) && in_array($attribute->name, ['class', 'data-task-state', 'aria-label'], true)) {
                continue;
            }
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

        return ['tag' => $node->tagName, 'attributes' => $attributes, 'children' => $children];
    }

    /**
     * @return array<string>
     */
    private function codePayloads(Node $node, bool $original = false): array
    {
        if ($node instanceof CodeBlock) {
            if ($original) {
                $this->assertSame(1, $node->getPos()?->endLine);
            }

            return [$node->getContent()];
        }
        if ($node instanceof RawBlock) {
            if ($original) {
                $this->assertSame('', $node->getContent());
                $this->assertSame(1, $node->getPos()?->endLine);
            }
        }
        $result = [];
        foreach ($node->getChildren() as $child) {
            array_push($result, ...$this->codePayloads($child, $original));
        }

        return $result;
    }
}
