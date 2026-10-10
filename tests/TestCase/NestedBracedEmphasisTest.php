<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Parser\InlineParser;
use PHPUnit\Framework\TestCase;

class NestedBracedEmphasisTest extends TestCase
{
    private function semantic(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['pos', 'srcByteLength', 'bulletChar', 'number'], true)) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = $this->semantic($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    private function unwrapPadding(array &$node, int $padding): bool
    {
        if (($node['children'][0]['type'] ?? null) === 'emphasis') {
            $children = $node['children'];
            for ($index = 0; $index < $padding; $index++) {
                $children = $children[0]['children'];
            }
            $node['children'] = $children;
            return true;
        }
        foreach ($node as &$value) {
            if (is_array($value) && $this->unwrapPadding($value, $padding)) {
                return true;
            }
        }
        return false;
    }

    public function testSharedHostDepthVectors(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../fixtures/nested-braced-emphasis.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($vectors['hostCases'] as $vector) {
            if (!isset($vector['remainingInlineDepth'])) {
                continue;
            }
            $padding = InlineParser::MAX_INLINE_DEPTH - $vector['remainingInlineDepth'] - 1;
            $source = str_replace('{^{^x^}^}', str_repeat('{/', $padding) . '{^{^x^}^}' . str_repeat('/}', $padding), $vector['source']);
            $ast = $codec->encode($converter->parse($source));
            $this->assertTrue($this->unwrapPadding($ast, $padding), $vector['id']);
            if ($vector['id'] === 'host-depth-heading') {
                unset($ast['children'][0]['attrs']);
            }
            $this->assertSame($this->semantic($vector['document']), $this->semantic($ast), $vector['id']);
        }
    }

    public function testSharedDepthVectors(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../fixtures/nested-braced-emphasis.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($vectors['depthCases'] as $vector) {
            $padding = InlineParser::MAX_INLINE_DEPTH - $vector['remainingInlineDepth'] - 1;
            $source = str_repeat('{/', $padding) . $vector['source'] . str_repeat('/}', $padding);
            $ast = $codec->encode($converter->parse($source));
            $children = $ast['children'][0]['children'];
            for ($index = 0; $index < $padding; $index++) {
                $children = $children[0]['children'];
            }
            $this->assertSame($this->semantic($vector['children']), $this->semantic($children), $vector['id']);
        }
    }

    public function testCommentAndUnclosedCodeBoundaries(): void
    {
        $converter = new CarveConverter();
        foreach ([
            ['{*a {% *} %} b*}', '<p><strong>a  b</strong></p>'],
            ['{*a {# *} #} b*}', '<p><strong>a <span class="critic-comment"> *} </span> b</strong></p>'],
            ['a {*b %% c*}', '<p>a <strong>b</strong></p>'],
            ['{*a `{*}', '<p><strong>a <code>{</code></strong></p>'],
        ] as [$source, $html]) {
            $this->assertSame($html, trim($converter->convert($source)), $source);
            $written = CarveConverter::toCarve($source);
            $this->assertSame($html, trim($converter->convert($written)), $source);
        }
    }

    public function testMalformedRecoveryVectors(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../fixtures/malformed-braced-emphasis.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($vectors as $vector) {
            $this->assertSame($vector['html'], trim($converter->convert($vector['source'])), $vector['id']);
            $written = CarveConverter::toCarve($vector['source']);
            $this->assertSame($vector['html'], trim($converter->convert($written)), $vector['id']);
        }
    }

    public function testHtmlImportReportsTheNativeDepthBudget(): void
    {
        $depth = InlineParser::MAX_INLINE_DEPTH + 20;
        $html = '<p>' . str_repeat('<strong>', $depth) . 'x' . str_repeat('</strong>', $depth) . '</p>';
        $importer = new HtmlToCarve();
        $result = $importer->convertWithReport($html);
        $rendered = trim((new CarveConverter())->convert($result->value));
        $this->assertSame('<p>' . str_repeat('<strong>', $depth - 21) . 'x' . str_repeat('</strong>', $depth - 21) . '</p>', $rendered);
        $losses = array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'structure-unspellable');
        $this->assertCount(21, $losses);
        foreach ($losses as $loss) {
            $this->assertStringContainsString('native nesting budget', $loss->message);
        }
        $ast = $importer->convertToAst($html);
        $children = $ast['children'][0]['children'];
        $count = 0;
        while (($children[0]['type'] ?? null) === 'strong') {
            $count++;
            $children = $children[0]['children'];
        }
        $this->assertSame($depth, $count);
    }

    public function testSharedParsingAndCanonicalVectors(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../fixtures/nested-braced-emphasis.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach (array_merge($vectors['cases'], $vectors['hostCases']) as $vector) {
            if (isset($vector['remainingInlineDepth'])) {
                continue;
            }
            $ast = $codec->encode($converter->parse($vector['source']));
            if (isset($vector['children'])) {
                $this->assertSame($this->semantic($vector['children']), $this->semantic($ast['children'][0]['children']), $vector['id']);
            }
            if (isset($vector['document'])) {
                if ($vector['id'] === 'host-heading') {
                    unset($ast['children'][0]['attrs']['id']);
                    if ($ast['children'][0]['attrs'] === []) {
                        unset($ast['children'][0]['attrs']);
                    }
                }
                $this->assertSame($this->semantic($vector['document']), $this->semantic($ast), $vector['id']);
            }
            $html = trim($converter->convert($vector['source']));
            $sharedHtml = str_contains($vector['html'], '<section>') ? preg_replace('/<section id="[^"]*">/', '<section>', $html) : $html;
            $this->assertSame($vector['html'], $sharedHtml, $vector['id']);
            $written = CarveConverter::toCarve($vector['source']);
            $this->assertSame($html, trim($converter->convert($written)), $vector['id']);
            $this->assertSame($written, CarveConverter::toCarve($written), $vector['id']);
            if (isset($vector['canonical'])) {
                $this->assertSame($vector['canonical'], rtrim($written), $vector['id']);
            }
        }
    }
}
