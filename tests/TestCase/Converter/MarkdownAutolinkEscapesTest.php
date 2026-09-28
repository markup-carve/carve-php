<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownAutolinkEscapesTest extends TestCase
{
    public function testAutolinkEscapeSemantics(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "<https://example.com/\\[\\>",
    "<p><a href=\"https://example.com/%5C%5B%5C\">https://example.com/\\[\\</a></p>"
  ],
  [
    "<foo\\+@bar.example.com>",
    "<p>&lt;foo+@bar.example.com&gt;</p>"
  ],
  [
    "<foo+@bar.example.com>",
    "<p><a href=\"mailto:foo+@bar.example.com\">foo+@bar.example.com</a></p>"
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
