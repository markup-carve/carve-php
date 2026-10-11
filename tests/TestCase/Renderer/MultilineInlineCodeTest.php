<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MultilineInlineCodeTest extends TestCase
{
    public static function controls(): iterable
    {
        $controls = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/multiline-inline-code.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($controls as $index => $control) {
            yield 'native case ' . $index => [$control];
        }
    }

    #[DataProvider('controls')]
    public function testMultilineCodeKeepsItsPayloadAcrossRenderPaths(array $control): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse($control['source']);
        $written = (new CarveRenderer())->render($document);
        foreach ([$converter->convert($control['source']), $converter->render($document), $converter->convert($written)] as $html) {
            $this->assertSame($this->htmlTree($control['html']), $this->htmlTree($html), $control['source']);
        }
    }

    public function testTheLegacySentinelHookAndFootnoteCharactersRemainUsable(): void
    {
        $renderer = new class extends CarveRenderer {
            protected function pickVerbatimSentinels(string $text): array
            {
                $picked = parent::pickVerbatimSentinels($text);
                Assert::assertCount(8, $picked);

                return $picked;
            }
        };
        $converter = new CarveConverter();
        foreach (
            [
                "`\u{E009}`[^n]\n\n[^n]: p `a\n  [r]: /url` q\n",
                "x[^n]\n\n[^n]: `\u{E009}[`\n",
                "`\u{E001}\u{E002}\u{E003}\u{E004}\u{E005}\u{E006}\u{E007}\u{E008}\u{E009}`[^n]\n\n[^n]: p `a\n  [r]: /url` q\n",
            ] as $source
        ) {
            $written = $renderer->render($converter->parse($source));
            $this->assertSame($this->htmlTree($converter->convert($source)), $this->htmlTree($converter->convert($written)));
            $this->assertStringContainsString("\u{E009}", $written);
            $this->assertSame($written, $renderer->render($converter->parse($written)));
        }
    }

    public function testConstructedCodeNormalizesLineEndingsAndPreservesItsValue(): void
    {
        $converter = new CarveConverter();
        foreach (["a\rb`` end", "a\r\nb`` end", "a\rb\r\nc``` end"] as $value) {
            $document = $converter->parse("`X`\n");
            $code = $document->getChildren()[0]->getChildren()[0];
            $this->assertInstanceOf(Code::class, $code);
            $code->setContent($value);
            $written = (new CarveRenderer())->render($document);
            $reparsed = $converter->parse($written);
            $this->assertCount(1, $reparsed->getChildren()[0]->getChildren());
            $read = $reparsed->getChildren()[0]->getChildren()[0];
            $this->assertInstanceOf(Code::class, $read);
            $this->assertSame(str_replace(["\r\n", "\r"], "\n", $value), $read->getContent());
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
            return !$literal && trim($node->data) === '' ? [] : ['text' => $literal ? $node->data : preg_replace('/[\t\n\r\f ]+/', ' ', $node->data)];
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
