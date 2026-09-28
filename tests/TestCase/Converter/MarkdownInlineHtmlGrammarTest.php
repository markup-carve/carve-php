<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownInlineHtmlGrammarTest extends TestCase
{
    public function testInlineHtmlTagBoundaries(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "a <b/> c",
    "<p>a <b/> c</p>"
  ],
  [
    "a <b > c </b > d",
    "<p>a <b > c </b > d</p>"
  ],
  [
    "x <span>a\n*b*</span>",
    "<p>x <span>a\n<em>b</em></span></p>"
  ],
  [
    "<a  /><b2\ndata=\"foo\" >\n",
    "<p><a  /><b2\ndata=\"foo\" ></p>"
  ],
  [
    "<a foo=\"bar\" bam = 'baz <em>\"</em>'\n_boolean zoop:33=zoop:33 />\n",
    "<p><a foo=\"bar\" bam = 'baz <em>\"</em>'\n_boolean zoop:33=zoop:33 /></p>"
  ],
  [
    "Foo <responsive-image src=\"foo.jpg\" />\n",
    "<p>Foo <responsive-image src=\"foo.jpg\" /></p>"
  ],
  [
    "<33> <__>\n",
    "<p>&lt;33&gt; &lt;__&gt;</p>"
  ],
  [
    "<a h*#ref=\"hi\">\n",
    "<p>&lt;a h*#ref=\"hi\"&gt;</p>"
  ],
  [
    "<a href=\"hi'> <a href=hi'>\n",
    "<p>&lt;a href=\"hi'&gt; &lt;a href=hi'&gt;</p>"
  ],
  [
    "< a><\nfoo><bar/ >\n<foo bar=baz\nbim!bop />\n",
    "<p>&lt; a&gt;&lt;\nfoo&gt;&lt;bar/ &gt;\n&lt;foo bar=baz\nbim!bop /&gt;</p>"
  ],
  [
    "<a href='bar'title=title>\n",
    "<p>&lt;a href='bar'title=title&gt;</p>"
  ],
  [
    "</a></foo >\n",
    "<p></a></foo ></p>"
  ],
  [
    "</a href=\"foo\">\n",
    "<p>&lt;/a href=\"foo\"&gt;</p>"
  ],
  [
    "foo <!-- this is a --\ncomment - with hyphens -->\n",
    "<p>foo <!-- this is a --\ncomment - with hyphens --></p>"
  ],
  [
    "foo <!--> foo -->\n\nfoo <!---> foo -->\n",
    "<p>foo <!--> foo --&gt;</p>\n<p>foo <!---> foo --&gt;</p>"
  ],
  [
    "foo <?php echo $a; ?>\n",
    "<p>foo <?php echo $a; ?></p>"
  ],
  [
    "foo <!ELEMENT br EMPTY>\n",
    "<p>foo <!ELEMENT br EMPTY></p>"
  ],
  [
    "foo <![CDATA[>&<]]>\n",
    "<p>foo <![CDATA[>&<]]></p>"
  ],
  [
    "foo <a href=\"&ouml;\">\n",
    "<p>foo <a href=\"&ouml;\"></p>"
  ],
  [
    "foo <a href=\"\\*\">\n",
    "<p>foo <a href=\"\\*\"></p>"
  ],
  [
    "<a href=\"\\\"\">\n",
    "<p>&lt;a href=\"\"\"&gt;</p>"
  ],
  [
    "a\\ b",
    "<p>a\\ b</p>"
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
