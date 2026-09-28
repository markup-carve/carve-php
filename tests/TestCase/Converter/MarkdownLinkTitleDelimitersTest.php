<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownLinkTitleDelimitersTest extends TestCase
{
    public function testLinkTitlesAreValidatedBeforeDecoding(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "[a](url &quot;tit&quot;)",
    "<p>[a](url \"tit\")</p>"
  ],
  [
    "[link](/url \"title \"and\" title\")",
    "<p>[link](/url \"title \"and\" title\")</p>"
  ],
  [
    "[link](/url (title))",
    "<p><a href=\"/url\" title=\"title\">link</a></p>"
  ],
  [
    "[link](   /uri\n  \"title\"  )",
    "<p><a href=\"/uri\" title=\"title\">link</a></p>"
  ],
  [
    "[a](/u\n(title))",
    "<p><a href=\"/u\" title=\"title\">a</a></p>"
  ],
  [
    "[a](/u (ti\ntle))",
    "<p><a href=\"/u\" title=\"ti\ntle\">a</a></p>"
  ],
  [
    "[a](/u\n\"t\"\n)",
    "<p><a href=\"/u\" title=\"t\">a</a></p>"
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
