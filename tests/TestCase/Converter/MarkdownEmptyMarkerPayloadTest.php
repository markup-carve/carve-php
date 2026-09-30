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

    public function testALooseInnerListLeavesTheOuterListTight(): void
    {
        $carve = (new MarkdownToCarve())->convert("- a\n  - b\n\n    c\n- d\n");
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString("<li>a\n", $html);
        $this->assertStringContainsString('<li>d</li>', $html);
        $this->assertStringContainsString('<li><p>b</p>', $html);
        $this->assertStringContainsString('<p>c</p>', $html);
    }
}
