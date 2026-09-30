<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownEmptyMarkerPayloadTest extends TestCase
{
    public function testEmptyMarkersTakeImmediatePayloads(): void
    {
        $source = "-\n  foo\n-\n  ```\n  bar\n  ```\n-\n      baz\n";
        $expected = "<ul>\n  <li>foo</li>\n  <li>\n    <pre><code>bar\n</code></pre>\n  </li>\n  <li>\n    <pre><code>baz\n</code></pre>\n  </li>\n</ul>";
        $carve = (new MarkdownToCarve())->convert($source);
        $this->assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }

    public function testPayloadBoundaryControls(): void
    {
        foreach (["-\n  ---", "*\n  ***"] as $source) {
            $carve = (new MarkdownToCarve())->convert($source);
            $this->assertSame("<ul>\n  <li>\n    <hr>\n  </li>\n</ul>", trim((new CarveConverter())->convert($carve)));
        }
        $nested = (new MarkdownToCarve())->convert("-\n\t-\n\t  ---");
        $nestedHtml = (new CarveConverter())->convert($nested);
        $this->assertSame(2, substr_count($nestedHtml, '<ul>'));
        $this->assertStringContainsString('<hr>', $nestedHtml);
        $carve = (new MarkdownToCarve())->convert("-\n  -\n    foo");
        $this->assertSame("<ul>\n  <li>\n    <ul>\n      <li>foo</li>\n    </ul>\n  </li>\n</ul>", trim((new CarveConverter())->convert($carve)));
        $carve = (new MarkdownToCarve())->convert("-\n\t\tfoo");
        $this->assertStringContainsString("<pre><code>  foo\n</code></pre>", (new CarveConverter())->convert($carve));
    }

    public function testPayloadSlackKeepsTheOriginalContentColumn(): void
    {
        $carve = (new MarkdownToCarve())->convert("-\n    foo\n\n  bar");
        $html = (new CarveConverter())->convert($carve);
        $this->assertSame(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('<p>foo</p>', $html);
        $this->assertStringContainsString('<p>bar</p>', $html);
    }

    public function testBlockPayloadsKeepTheirSourceColumns(): void
    {
        foreach ([3, 4, 5] as $column) {
            $pad = str_repeat(' ', $column);
            $carve = (new MarkdownToCarve())->convert("-\n{$pad}```\n{$pad}x\n{$pad}```");
            $this->assertStringContainsString("<pre><code>x\n</code></pre>", (new CarveConverter())->convert($carve));
        }
        $carve = (new MarkdownToCarve())->convert("-\n    - a\n\n    b");
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString("</ul>\n    <p>b</p>", $html);
        $carve = (new MarkdownToCarve())->convert("-\n      ---\n- b");
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString("<pre><code>---\n</code></pre>", $html);
        $this->assertStringContainsString('<li>b</li>', $html);
    }

    public function testEmptyNestedMarkersAndSetextPayloads(): void
    {
        $carve = (new MarkdownToCarve())->convert("-\n  -\n- b");
        $html = (new CarveConverter())->convert($carve);
        $this->assertSame(2, substr_count($html, '<ul>'));
        $this->assertStringContainsString('<li></li>', $html);
        foreach ([['===', 'h1'], ['---', 'h2']] as [$underline, $heading]) {
            $carve = (new MarkdownToCarve())->convert("-\n  foo\n  {$underline}");
            $html = (new CarveConverter())->convert($carve);
            $this->assertStringContainsString("<{$heading} id=\"foo\">foo</{$heading}>", $html);
        }
    }

    public function testALooseInnerListLeavesTheOuterListTight(): void
    {
        $carve = (new MarkdownToCarve())->convert("- a\n  - b\n\n    c\n- d\n");
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString("<li>a\n", $html);
        $this->assertStringContainsString('<li>d</li>', $html);
        $this->assertStringContainsString('<li><p>b</p>', $html);
        $this->assertStringContainsString('<p>c</p>', $html);
    }

    public function testHeadingPayloadsKeepSiblingsTight(): void
    {
        foreach (['  # foo', "  foo\n  ===", "  foo\n  ---"] as $payload) {
            $carve = (new MarkdownToCarve())->convert("-\n{$payload}\n- b");
            $this->assertStringContainsString('<li>b</li>', (new CarveConverter())->convert($carve));
        }
    }

    public function testOutdentedTextLeavesTheEmptyItem(): void
    {
        $carve = (new MarkdownToCarve())->convert("-\nfoo\n- b");
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString('<li></li>', $html);
        $this->assertStringContainsString('<p>foo</p>', $html);
        $this->assertSame(2, substr_count($html, '<ul>'));
    }
}
