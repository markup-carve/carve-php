<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownEmptyHeadingsTest extends TestCase
{
    public function testEmptyHeadingsKeepTheirElements(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "## \n#\n### ###\n",
    "<h2></h2>\n<h1></h1>\n<h3></h3>"
  ],
  [
    "before\n#\nafter",
    "<p>before</p>\n<h1></h1>\n<p>after</p>"
  ],
  [
    "    #\n",
    "<pre><code>#\n</code></pre>"
  ],
  [
    "#\n    code",
    "<h1></h1>\n<pre><code>code\n</code></pre>"
  ],
  [
    "1. item\n\n   #",
    "<ol>\n  <li><p>item</p>\n    <h1></h1>\n  </li>\n</ol>"
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
