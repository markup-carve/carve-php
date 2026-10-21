<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachedDefinitionBoundariesTest extends TestCase
{
    public static function controls(): iterable
    {
        $controls = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/attached-definition-boundaries.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($controls as $index => $control) {
            yield 'native case ' . $index => [$control, $index];
        }
    }

    #[DataProvider('controls')]
    public function testDefinitionsKeepTheirAttachedBlockOwnership(array $control, int $index): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse($control['source']);
        $written = (new CarveRenderer())->render($document);
        foreach ([$converter->convert($control['source']), $converter->render($document), $converter->convert($written)] as $html) {
            $this->assertSame($this->htmlTree($control['html']), $this->htmlTree($html), $control['source']);
        }
    }

    public function testQuotedAttachedListKeepsItsReferenceDefinition(): void
    {
        $source = "> :: term\n> : body\n> +\n> - inner\n>   [r]: /url\n\nSee [r][].\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<a href="/url">r</a>', $html);
    }

    public function testQuotedOrderedAttachmentKeepsTheSecondReferenceAsText(): void
    {
        $source = "> :: term\n> : body\n> +\n> 1. inner\n>    [r]: /a\n>   [s]: /b\n\nSee [r][] [s][].\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<p>[s]: /b</p>', $html);
        $this->assertStringNotContainsString('href="/b"', $html);
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
            return !$literal && trim($node->data) === '' ? [] : ['text' => $literal ? $node->data : preg_replace('/[\t\n\r\f ]+/', ' ', $node->data)];
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
