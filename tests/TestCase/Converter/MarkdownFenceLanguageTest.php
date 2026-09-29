<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownFenceLanguageTest extends TestCase
{
    public function testFenceLanguage(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "```&#99;\nx\n```",
    "<pre><code class=\"language-c\">x\n</code></pre>"
  ],
  [
    "```c\\#\nx\n```",
    "<pre><code class=\"language-c#\">x\n</code></pre>"
  ],
  [
    "```a b\nx\n```",
    "<pre><code>x\n</code></pre>"
  ]
,
  [
    "``` f&ouml;&ouml;\nfoo\n```\n",
    "<pre><code>foo\n</code></pre>"
  ],
  [
    "````;\n````\n",
    "<pre><code></code></pre>"
  ],
  [
    "```=html\n<script>x</script>\n```",
    "<pre><code>&lt;script&gt;x&lt;/script&gt;\n</code></pre>"
  ],
  [
    "~~~a`b\nx\n~~~",
    "<pre><code>x\n</code></pre>"
  ],
  [
    "```a\"b\n\"x\"\n```",
    "<pre><code>\"x\"\n</code></pre>"
  ],
  [
    "```c++ title=x\nx\n```",
    "<pre><code class=\"language-c++\">x\n</code></pre>"
  ],
  [
    "- ```föö\n  x\n  ```",
    "<ul>\n  <li>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>"
  ],
  [
    "> ```föö\n> x\n> ```",
    "<blockquote>\n  <pre><code>x\n</code></pre>\n</blockquote>"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as [$source, $expected]) {
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"), $source);
        }
    }
}
