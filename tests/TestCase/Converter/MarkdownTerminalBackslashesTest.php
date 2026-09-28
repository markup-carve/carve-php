<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownTerminalBackslashesTest extends TestCase
{
    public function testTerminalBackslashesRemainLiteral(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "foo\\",
    "<p>foo\\</p>"
  ],
  [
    "foo\\\n",
    "<p>foo\\</p>"
  ],
  [
    "foo\\\n\nbar",
    "<p>foo\\</p>\n<p>bar</p>"
  ],
  [
    "foo\\\nbar",
    "<p>foo<br>\nbar</p>"
  ],
  [
    "### foo\\\n",
    "<section id=\"foo\">\n  <h3>foo\\</h3>\n</section>"
  ],
  [
    "Foo\\\n---\n",
    "<section id=\"Foo\">\n  <h2>Foo\\</h2>\n</section>"
  ],
  [
    "Foo\nbar\\\n---\n",
    "<section id=\"Foo-bar\">\n  <h2>Foo bar\\</h2>\n</section>"
  ],
  [
    "> foo\\\n",
    "<blockquote><p>foo\\</p></blockquote>"
  ],
  [
    "- foo\\\n",
    "<ul>\n  <li>foo\\</li>\n</ul>"
  ],
  [
    "> a\\\nb",
    "<blockquote><p>a<br>\nb</p></blockquote>"
  ],
  [
    "- a\\\nb",
    "<ul>\n  <li>a<br>\nb</li>\n</ul>"
  ],
  [
    "- a\n  b\\\n  c",
    "<ul>\n  <li>a\nb<br>\nc</li>\n</ul>"
  ],
  [
    "- a\nb\\\nc",
    "<ul>\n  <li>a\nb<br>\nc</li>\n</ul>"
  ],
  [
    "a\n    b\\\n    c",
    "<p>a\nb<br>\nc</p>"
  ],
  [
    "> a\nb\\\nc",
    "<blockquote><p>a\nb<br>\nc</p></blockquote>"
  ],
  [
    "> a\\\n> ---",
    "<blockquote>\n  <h2 id=\"a\">a\\</h2>\n</blockquote>"
  ],
  [
    "- a\\\n  ---",
    "<ul>\n  <li>\n    <h2 id=\"a\">a\\</h2>\n  </li>\n</ul>"
  ],
  [
    "Foo\\\n    ---",
    "<p>Foo<br>\n---</p>"
  ],
  [
    "- > foo\\\n  > bar",
    "<ul>\n  <li>\n    <blockquote><p>foo<br>\nbar</p></blockquote>\n  </li>\n</ul>"
  ],
  [
    "- > foo  \n  > bar",
    "<ul>\n  <li>\n    <blockquote><p>foo<br>\nbar</p></blockquote>\n  </li>\n</ul>"
  ],
  [
    "> a\nb\\\n> c",
    "<blockquote><p>a\nb<br>\nc</p></blockquote>"
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
