<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class HtmlCodeLanguagesTest extends TestCase
{
    public function testSharedLanguageCases(): void
    {
        $path = dirname(__DIR__, 2) . '/spec/tests/html-code-language-cases.json';
        $cases = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $converter = new HtmlToCarve(importMode: $mode);
            foreach ($cases as $case) {
                $ast = $converter->convertToAstWithReport($case['html']);
                $languages = [];
                $blocks = [];
                $walk = static function (array $node) use (&$walk, &$languages, &$blocks): void {
                    if (($node['type'] ?? null) === 'code_block') {
                        $languages[] = $node['lang'] ?? null;
                        $blocks[] = ['lang' => $node['lang'] ?? null, 'content' => $node['content']];
                    }
                    foreach ($node as $value) {
                        if (is_array($value)) {
                            $walk($value);
                        }
                    }
                };
                $walk($ast->value);
                $this->assertSame($case['languages'], $languages, $mode . ': ' . $case['name']);
                $expectedBlocks = $blocks;
                $source = $converter->convertWithReport($case['html'])->value;
                $parsed = (new CarveConverter())->parse($source);
                $blocks = [];
                $walk((new AstCodec())->encode($parsed));
                $this->assertSame($expectedBlocks, $blocks, $mode . ': ' . $case['name']);
            }
        }
    }

    public function testRepresentableFixtureAndReportsAreStableInEveryMode(): void
    {
        $root = dirname(__DIR__, 2) . '/spec/tests/html-import/code-language-hints/';
        $html = (string)file_get_contents($root . 'input.html');
        $source = (string)file_get_contents($root . 'expected.crv');
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame($source, $result->value);
            $this->assertSame([], $result->diagnostics);
            $parsed = (new CarveConverter())->parse($source);
            $this->assertSame($source, (new CarveRenderer())->render($parsed));
        }
    }

    public function testRawPreservationDoesNotPromoteNestedCode(): void
    {
        $html = '<figure><div class="panel"><pre data-lang="js">x</pre></div><figcaption>c</figcaption></figure>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertToAstWithReport($html);
        $this->assertSame('raw_block', $result->value['children'][0]['type']);
        $this->assertContains('raw-preserved', array_column($result->report()['diagnostics'], 'code'));
    }

    public function testASharedWrapperIsNotRescannedPerPre(): void
    {
        $comments = str_repeat('<!-- x -->', 40000);
        $pres = str_repeat('<pre>x</pre>', 40000);
        $measure = static function (string $body): float {
            $start = microtime(true);
            (new HtmlAstBuilder())->build('<div class="highlight highlight-source-js">' . $body . '</div>');

            return microtime(true) - $start;
        };
        (new HtmlAstBuilder())->build(str_repeat('<pre>x</pre>', 1000));
        $baseline = $measure($pres . $comments);
        $this->assertLessThan($baseline * 4 + 0.25, $measure($comments . $pres));
    }

    public function testFigureCollisionsUseTheSharedDiagnostic(): void
    {
        foreach (['pre', 'table'] as $tag) {
            foreach (['id', 'data-x'] as $name) {
                $body = $tag === 'table' ? '<tr><td>x</td></tr>' : 'x';
                $html = '<figure ' . $name . '="a"><' . $tag . ' ' . $name . '="b">' . $body . '</' . $tag . '><figcaption>c</figcaption></figure>';
                $report = (new HtmlToCarve())->convertWithReport($html)->report();
                $messages = array_column($report['diagnostics'], 'message');
                $this->assertContains('Dropped one ' . $name . ' on <figure>: the figure and its target both set ' . $name . ', and their two attribute lines merge into a single value', $messages);
            }
        }
    }

    public function testFigureReportsOnlyAttributesThatWereMerged(): void
    {
        foreach (
            [
                '<figure role="note"><pre role="img">x</pre><figcaption>c</figcaption></figure>',
                '<figure id="outer"><p id="inner">text</p><figcaption>c</figcaption></figure>',
                '<figure id="outer"><p id="inner"><img src="x" alt="x"></p><figcaption>c</figcaption></figure>',
            ] as $html
        ) {
            $report = (new HtmlToCarve())->convertWithReport($html)->report();
            foreach ($report['diagnostics'] as $row) {
                $this->assertStringNotContainsString('Dropped one ', $row['message']);
            }
        }
    }
}
