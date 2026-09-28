<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotInvalidAttributesTest extends TestCase
{
    public function testInvalidAttributesRemainLiteral(): void
    {
        $cases = json_decode(<<<'JSON'
[
  ["[hi]{#id key=\"<x:y>\"}", "<p><span id=\"id\" key=\"&lt;x:y&gt;\">hi</span></p>"],
  ["[hi]{#id key=\"a `b` c\"}", "<p><span id=\"id\" key=\"a `b` c\">hi</span></p>"],
  [
    "[not a span]{#a<b}\n",
    "<p>[not a span]{#a&lt;b}</p>"
  ],
  [
    "[*not* a span]{#a<b}\n",
    "<p>[<strong>not</strong> a span]{#a&lt;b}</p>"
  ],
  [
    "[not a span]{#a\n",
    "<p>[not a span]{#a</p>"
  ],
  [
    "[hi]{#id key=\"{#x\"}",
    "<p><span id=\"id\" key=\"{#x\">hi</span></p>"
  ],
  [
    "`{#a<b}`",
    "<p><code>{#a&lt;b}</code></p>"
  ],
  [
    "{#a `x}` y",
    "<p>{#a <code>x}</code> y</p>"
  ],
  [
    "{#a\\} b",
    "<p>{#a} b</p>"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as [$source, $expected]) {
            $converted = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"));
        }
    }

    public function testUnterminatedAttributesDoNotInventHashtags(): void
    {
        $converted = (new DjotToCarve())->convert('{#id .cla*ss*');
        $html = (new CarveConverter())->convert($converted);
        $this->assertStringNotContainsString('class="tag"', $html);
        $this->assertStringContainsString('{#id', $html);
    }

    public function testScanningResumesAfterAnUnfinishedAttributeParagraph(): void
    {
        foreach (["a {\"q\n\nlater {#b", "a {%q\n\nlater {#b"] as $source) {
            $converted = (new DjotToCarve())->convert($source);
            $this->assertStringNotContainsString('class="tag"', (new CarveConverter())->convert($converted));
        }
        $this->assertStringContainsString('<https://x.y/{#a>', (new DjotToCarve())->convert('<https://x.y/{#a>'));
    }
}
