<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\SafeMode;
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
                $this->assertCount(str_contains($result->value, '{=html}') ? 1 : 0, $fallback);
                if ($case['value'] !== '' && strpbrk($case['value'], "\r\n") === false) {
                    $this->assertCount(0, $fallback);
                    $safe = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_STRIP);
                    $this->assertSame($expected, $this->records((new CarveConverter(safeMode: $safe))->convert($result->value))['codes']);
                }
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
                if ($convertRawHtml && str_starts_with($case['markdown'], '<?')) {
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

    public function testNativeParagraphCodeSurvivesRawHtmlStripping(): void
    {
        foreach ([false, true] as $mode) {
            $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport('<code>a<!---->&#10;<!---->b</code>');
            $this->assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback')));
            $safe = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_STRIP);
            $this->assertSame("<p><code>a\nb</code></p>", rtrim((new CarveConverter(safeMode: $safe))->convert($result->value), "\n"));
        }
    }

    public function testNativeCodeProbeDoesNotReportADiscardedLoss(): void
    {
        foreach ([false, true] as $mode) {
            $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport('<code>&#32;a_b</code>');
            $this->assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable')));
            $safe = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_STRIP);
            $this->assertSame('<p><code> a_b</code></p>', rtrim((new CarveConverter(safeMode: $safe))->convert($result->value), "\n"));
        }
    }

    public function testNestedBoldItalicAndUnicodeCodeStayNative(): void
    {
        foreach ([false, true] as $mode) {
            $safe = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_STRIP);
            foreach (['<em><strong>x</strong></em>', '<i><b>x</b></i>'] as $markdown) {
                $source = (new MarkdownToCarve(convertRawHtml: $mode))->convert($markdown);
                $html = (new CarveConverter(safeMode: $safe))->convert($source);
                $this->assertStringContainsString('<strong>', $html);
                $this->assertStringContainsString('<em>', $html);
            }
            foreach (['café_au', '名前_id', 'größ_e', 'int[][]', 'm[i][j]', 'a ~~ b ~~ c'] as $value) {
                $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport('<code>' . $value . '</code>');
                $this->assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback')));
                $this->assertSame('<p><code>' . $value . '</code></p>', rtrim((new CarveConverter(safeMode: $safe))->convert($result->value), "\n"));
            }
        }
    }

    public function testConvertedHtmlKeepsProtectedInlineContent(): void
    {
        foreach (['<span>a <code>b</code></span>' => '<p>a <code>b</code></p>', '<small>a\\*b</small>' => '<p>a*b</p>', '<span>*a* [b](u)</span>' => '<p><em>a</em> <a href="u">b</a></p>', '<span>```a```</span>' => '<p><code>a</code></p>'] as $markdown => $expected) {
            $source = (new MarkdownToCarve(convertRawHtml: true))->convert($markdown);
            $this->assertSame($expected, str_replace(['<s>', '</s>'], ['<del>', '</del>'], rtrim((new CarveConverter())->convert($source), "\n")));
        }
    }

    public function testWholeHtmlConversionDoesNotReportDiscardedFallbacks(): void
    {
        foreach (['<span>a] <code class="x">b</code></span>', 'x <p><code></code></p>'] as $markdown) {
            $result = (new MarkdownToCarve(convertRawHtml: true))->convertWithFidelityReport($markdown);
            $this->assertStringNotContainsString('{=html}', $result->value);
            $this->assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback')));
        }
    }

    public function testFormattingOutsideCodeKeepsItsStructure(): void
    {
        foreach ([false, true] as $mode) {
            foreach (
                [
                    '<em><strong>x</strong></em>' => '<p><em><strong>x</strong></em></p>',
                    '<b>x<sup>2</sup></b>' => '<p><strong>x<sup>2</sup></strong></p>',
                    '<em>H<sub>2</sub>O</em>' => '<p><em>H<sub>2</sub>O</em></p>',
                    '<strong><del>x</del></strong>' => '<p><strong><del>x</del></strong></p>',
                ] as $markdown => $expected
            ) {
                $source = (new MarkdownToCarve(convertRawHtml: $mode))->convert($markdown);
                $this->assertSame($expected, str_replace(['<s>', '</s>'], ['<del>', '</del>'], rtrim((new CarveConverter())->convert($source), "\n")));
            }
        }
    }

    public function testAutolinkDelimitersDoNotDegradeNativeCode(): void
    {
        foreach ([false, true] as $mode) {
            $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport('<code>*a</code> <http://e.test/b*>');
            $this->assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback')));
            $this->assertStringContainsString('<code>*a</code>', (new CarveConverter())->convert($result->value));
        }
    }

    public function testConvertedHtmlWrappersKeepLiteralBrackets(): void
    {
        $source = (new MarkdownToCarve(convertRawHtml: true))->convert('<a href="u">a]b</a> <span class="x">a[b</span>');
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<a href="u">a]b</a>', $html);
        $this->assertStringContainsString('a[b', $html);
    }

    public function testCodeFallbacksKeepTheirOriginalSourceLine(): void
    {
        foreach ([false, true] as $mode) {
            foreach (["Title\n<code>*a*</code>\n=====", "a\nb <code></code>"] as $markdown) {
                $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport($markdown);
                $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback'));
                $this->assertCount(1, $rows);
                $this->assertSame('line:2', $rows[0]->path);
            }
        }
    }

    public function testFootnoteShapedLabelWithDestinationIsALink(): void
    {
        foreach ([false, true] as $mode) {
            $source = (new MarkdownToCarve(convertRawHtml: $mode))->convert("x[^1](u<code>a</code>)\n\n[^1]: note");
            $html = (new CarveConverter())->convert($source);
            $this->assertSame('<p>x<a href="u%3Ccode%3Ea%3C/code%3E">^1</a></p>', rtrim($html, "\n"));
        }
    }

    public function testCodeNewlineEntitiesKeepLossSourceLines(): void
    {
        foreach ([false, true] as $mode) {
            $result = (new MarkdownToCarve(convertRawHtml: $mode))->convertWithFidelityReport("<code class=\"x\">a&#13;\nb</code> [t]()");
            $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            $this->assertCount(1, $rows);
            $this->assertSame('line:2', $rows[0]->path);
        }
    }

    public function testOnlyTheLastCodeStartsALinkAmongManyUnmatchedBrackets(): void
    {
        $count = 4000;
        foreach (['', '](u)'] as $tail) {
            $markdown = implode(' ', array_fill(0, $count, '<code>[x</code>')) . $tail;
            $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
            $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback'));
            $this->assertCount($tail === '' ? 0 : 1, $rows);
            $converter = new CarveConverter();
            $this->assertCount($count, $this->records($converter->convert($result->value))['codes']);
            $safe = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_STRIP);
            $this->assertCount($tail === '' ? $count : $count - 1, $this->records((new CarveConverter(safeMode: $safe))->convert($result->value))['codes']);
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
        $attributes = [];
        $visit = function (DOMNode $node, array $ancestors = []) use (&$visit, &$codes, &$roots, &$attributes): void {
            $next = $ancestors;
            // libxml omits the table body that HTML5 readers insert.
            if ($node instanceof DOMElement && !in_array($node->tagName, ['html', 'head', 'body', 'section', 'tbody'], true)) {
                $next[] = $node->tagName === 's' ? 'del' : $node->tagName;
            }
            if ($node instanceof DOMElement && in_array($node->tagName, ['a', 'img'], true)) {
                $row = ['tag' => $node->tagName];
                foreach (['href', 'title', 'src', 'alt'] as $name) {
                    if ($node->hasAttribute($name)) {
                        $row[$name] = $node->getAttribute($name);
                    }
                }
                $attributes[] = $row;
            }
            if ($node instanceof DOMElement && $node->tagName === 'code') {
                $elements = [];
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $elements[] = $child->tagName === 's' ? 'del' : $child->tagName;
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

        return ['codes' => $codes, 'roots' => $roots, 'attributes' => $attributes];
    }
}
