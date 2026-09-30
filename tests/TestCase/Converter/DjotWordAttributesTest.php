<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotWordAttributesTest extends TestCase
{
    public function testWordAttributes(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "x^a\\^b{.c}^",
    "<p>x<sup><span class=\"c\">a^b</span></sup></p>"
  ],
  [
    "a\\\\^b{.c}^",
    "<p>a\\<sup><span class=\"c\">b</span></sup></p>"
  ],
  [
    "x {.c}",
    "<p>x&nbsp;</p>"
  ]
,
  [
    "^b{.c}",
    "<p><span class=\"c\">^b</span></p>"
  ],
  [
    "a^b{.c}^",
    "<p>a<sup><span class=\"c\">b</span></sup></p>"
  ],
  [
    "a~b{.c}~ z",
    "<p>a<sub><span class=\"c\">b</span></sub> z</p>"
  ],
  [
    "| a |b{.c}|",
    "<table>\n  <tbody>\n    <tr><td>a</td><td><span class=\"c\">b</span></td></tr>\n  </tbody>\n</table>"
  ],
  [
    "x{_a=b_}",
    "<p>x<em>a=b</em></p>"
  ]
,
  [
    "_b{.c}_",
    "<p><em><span class=\"c\">b</span></em></p>"
  ],
  [
    "~b{.c}~",
    "<p><sub><span class=\"c\">b</span></sub></p>"
  ],
  [
    "{+b{.c}+}",
    "<p><ins><span class=\"c\">b</span></ins></p>"
  ],
  [
    "[a](b)c{.d}",
    "<p><a href=\"b\">a</a><span class=\"d\">c</span></p>"
  ],
  [
    "![i](s.png)t{.c}",
    "<p><img src=\"s.png\" alt=\"i\"><span class=\"c\">t</span></p>"
  ],
  [
    "<http://x.y>{.c}",
    "<p><a href=\"http://x.y\" class=\"c\">http://x.y</a></p>"
  ],
  [
    "x_y{.c}",
    "<p><span class=\"c\">x_y</span></p>"
  ]
,
  [
    "a *b{#id key=\"*\"}o\n",
    "<p>a <span id=\"id\" key=\"*\">*b</span>o</p>"
  ],
  [
    "hi{key=\"{#hi\"}\n",
    "<p><span key=\"{#hi\">hi</span></p>"
  ],
  [
    "hi\\{key=\"abc{#hi}\"\n",
    "<p>hi{key=\u201c<span id=\"hi\">abc</span>\u201d</p>"
  ],
  [
    "hi{#id .class\nkey=\"value\"}\n",
    "<p><span id=\"id\" class=\"class\" key=\"value\">hi</span></p>"
  ],
  [
    "foo{#ident % this is a comment % .class}\n",
    "<p><span id=\"ident\" class=\"class\">foo</span></p>"
  ],
  [
    "foo{#ident % this is a comment}\n",
    "<p><span id=\"ident\">foo</span></p>"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as [$source, $expected]) {
            $converted = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"), $source);
        }
    }
}
