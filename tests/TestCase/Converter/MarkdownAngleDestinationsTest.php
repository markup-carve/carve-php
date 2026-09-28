<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownAngleDestinationsTest extends TestCase
{
    public function testAngleDestinations(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "[link](</my uri>)\n",
    "<p><a href=\"/my%20uri\">link</a></p>"
  ],
  [
    "[link](foo\nbar)\n",
    "<p>[link](foo\nbar)</p>"
  ],
  [
    "[link](<foo\nbar>)\n",
    "<p>[link](<foo\nbar>)</p>"
  ],
  [
    "[a](<b)c>)\n",
    "<p><a href=\"b)c\">a</a></p>"
  ],
  [
    "[link](<foo\\>)\n",
    "<p>[link](&lt;foo&gt;)</p>"
  ],
  [
    "[a](<b)c\n[a](<b)c>\n[a](<b>c)\n",
    "<p>[a](&lt;b)c\n[a](&lt;b)c&gt;\n[a](<b>c)</p>"
  ],
  [
    "[link](\\(foo\\))\n",
    "<p><a href=\"(foo)\">link</a></p>"
  ],
  [
    "[link](foo(and(bar)))\n",
    "<p><a href=\"foo(and(bar))\">link</a></p>"
  ],
  [
    "[link](foo(and(bar))\n",
    "<p>[link](foo(and(bar))</p>"
  ],
  [
    "[link](foo\\(and\\(bar\\))\n",
    "<p><a href=\"foo(and(bar)\">link</a></p>"
  ],
  [
    "[link](<foo(and(bar)>)\n",
    "<p><a href=\"foo(and(bar)\">link</a></p>"
  ],
  [
    "[link](foo\\)\\:)\n",
    "<p><a href=\"foo):\">link</a></p>"
  ],
  [
    "[link](#fragment)\n\n[link](https://example.com#fragment)\n\n[link](https://example.com?foo=3#frag)\n",
    "<p><a href=\"#fragment\">link</a></p>\n<p><a href=\"https://example.com#fragment\">link</a></p>\n<p><a href=\"https://example.com?foo=3#frag\">link</a></p>"
  ],
  [
    "[link](foo\\bar)\n",
    "<p><a href=\"foo%5Cbar\">link</a></p>"
  ],
  [
    "[link](foo%20b&auml;)\n",
    "<p><a href=\"foo%20b%C3%A4\">link</a></p>"
  ],
  [
    "[link](\"title\")\n",
    "<p><a href=\"%22title%22\">link</a></p>"
  ],
  [
    "[link](/url \"title\")\n[link](/url 'title')\n[link](/url (title))\n",
    "<p><a href=\"/url\" title=\"title\">link</a>\n<a href=\"/url\" title=\"title\">link</a>\n<a href=\"/url\" title=\"title\">link</a></p>"
  ],
  [
    "[a](<b>\n\"t\")",
    "<p><a href=\"b\" title=\"t\">a</a></p>"
  ],
  [
    "[[a](b)](c) *x*",
    "<p>[<a href=\"b\">a</a>](c) <em>x</em></p>"
  ],
  [
    "[a](<b>\"t\")",
    "<p>[a](<b>\"t\")</p>"
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
