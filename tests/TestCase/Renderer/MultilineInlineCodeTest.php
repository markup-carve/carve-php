<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
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

    public function testPrivateFootnoteGuardIsRestoredBeforeAnOverriddenHook(): void
    {
        $renderer = new class extends CarveRenderer {
            protected function restoreVerbatim(string $text): string
            {
                return $text;
            }
        };
        $source = "x[^n]\n\n[^n]: p `a\n  [r]: /url` q\n";
        $written = $renderer->render((new CarveConverter())->parse($source));
        $this->assertStringNotContainsString("\u{E009}", $written);
        $this->assertStringContainsString("\n   [r]: /url", $written);
    }

    public function testDocumentsWithoutFootnotesPreserveAnEightCharacterFreeWindow(): void
    {
        $value = '';
        for ($point = 0xE009; $point <= 0xF8FF; $point++) {
            $value .= mb_chr($point, 'UTF-8');
        }
        $converter = new CarveConverter();
        $source = '`' . $value . "`\n";
        $written = (new CarveRenderer())->render($converter->parse($source));
        $this->assertSame($this->htmlTree($converter->convert($source)), $this->htmlTree($converter->convert($written)));
    }

    public function testLegacyPickerCanUseTheDefaultPrivateFootnoteMarker(): void
    {
        $renderer = new class extends CarveRenderer {
            protected function pickVerbatimSentinels(string $text): array
            {
                return array_map(static fn (int $point): string => mb_chr($point, 'UTF-8'), range(0xE002, 0xE009));
            }
        };
        $converter = new CarveConverter();
        $source = "x[^n]\n\n[^n]: p `a\n  [r]: /url` q\n";
        $written = $renderer->render($converter->parse($source));
        $this->assertSame($this->htmlTree($converter->convert($source)), $this->htmlTree($converter->convert($written)));
        $this->assertSame($written, $renderer->render($converter->parse($written)));
    }

    public function testFootnoteRefusalsIdentifyTheVerbatimNode(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach (['code', 'raw_inline', 'literal_inline', 'math'] as $type) {
            $wire = $codec->encode($converter->parse("x[^n]\n\n[^n]: body\n"));
            $node = ['type' => $type, $type === 'code' ? 'value' : 'content' => "x\n> y"];
            if ($type === 'raw_inline') {
                $node['format'] = 'html';
            } elseif ($type === 'math') {
                $node['display'] = false;
            }
            $wire['children'][1]['children'][0]['children'] = [$node];
            try {
                (new CarveRenderer())->render($codec->decode($wire));
                $this->fail('Expected a source refusal');
            } catch (SourceUnspellableException $exception) {
                $this->assertSame($type, $exception->nodeType);
                $this->assertSame('a block-marker continuation cannot stay inside a footnote paragraph', $exception->reason);
            }
        }
    }

    public function testNestedHostsPreserveDefinitionShapedCodePayloads(): void
    {
        $writer = CarveConverter::carve();
        $codec = new AstCodec();
        foreach (["- a\n  - b `x\n    [r]: /url` z\n", "> - a\n>   - b `x\n>     [r]: /url` z\n"] as $source) {
            $document = $writer->parse($source);
            $payloads = static function (array $tree) use (&$payloads): array {
                $found = ($tree['type'] ?? null) === 'code' ? [$tree['value']] : [];
                foreach ($tree as $child) {
                    if (is_array($child)) {
                        array_push($found, ...$payloads($child));
                    }
                }

                return $found;
            };
            $this->assertSame(["x\n[r]: /url"], $payloads($codec->encode($document)));
            $result = $writer->renderWithReport($document, strictLosses: true);
            $this->assertSame(["x\n[r]: /url"], $payloads($codec->encode($writer->parse($result->value))));
            $this->assertSame([], $result->losses);
            $this->assertSame($result->value, $writer->convert($result->value));
        }
    }

    public function testFootnoteReferenceContinuationsRefuseWithTheirNodeType(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach (['code', 'raw_inline', 'literal_inline', 'math'] as $type) {
            foreach (['', ' ', "\t"] as $padding) {
                $wire = $codec->encode($converter->parse("body[^n]\n\n[^n]: body\n"));
                $node = ['type' => $type, $type === 'code' ? 'value' : 'content' => "x`\n{$padding}[r]: /url\ntail"];
                if ($type === 'raw_inline') {
                    $node['format'] = 'html';
                } elseif ($type === 'math') {
                    $node['display'] = false;
                }
                foreach ($wire['children'] as &$child) {
                    if ($child['type'] === 'footnote') {
                        $child['children'][0]['children'] = [$node];
                    }
                }
                unset($child);
                try {
                    (new CarveRenderer())->render($codec->decode($wire));
                    $this->fail('Expected a source refusal');
                } catch (SourceUnspellableException $exception) {
                    $this->assertSame($type, $exception->nodeType);
                    $this->assertSame($padding === ''
                        ? 'a block-marker continuation cannot stay inside a footnote paragraph'
                        : 'a line of the value starts with whitespace, which the block layer strips', $exception->reason);
                }
            }
        }
    }

    public function testLegacyVerbatimGuardRestorationKeepsItsIndent(): void
    {
        $renderer = new class extends CarveRenderer {
            public function restoreLegacyGuard(string $tail): string
            {
                return $this->restoreVerbatim('  ' . $this->verbatimSentinels[7] . $tail);
            }
        };
        $this->assertSame('   [r]: /url', $renderer->restoreLegacyGuard('[r]: /url'));
        $this->assertSame(' > tail', $renderer->restoreLegacyGuard('> tail'));
    }

    public function testTermRefusalsIdentifyTheVerbatimNode(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach (['raw_inline', 'literal_inline', 'math'] as $type) {
            $wire = $codec->encode($converter->parse(":: term\n: body\n"));
            $node = ['type' => $type, 'content' => "x\n[r]: /url"];
            if ($type === 'raw_inline') {
                $node['format'] = 'html';
            } elseif ($type === 'math') {
                $node['display'] = false;
            }
            $wire['children'][0]['items'][0]['children'] = [$node];
            try {
                (new CarveRenderer())->render($codec->decode($wire));
                $this->fail('Expected a source refusal');
            } catch (SourceUnspellableException $exception) {
                $this->assertSame($type, $exception->nodeType);
                $this->assertSame('a block marker on a continuation line ends the definition term', $exception->reason);
            }
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
