<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownCodeSpanLinesTest extends TestCase
{
    public function testLineEndingsBecomeSpaces(): void
    {
        $cases = [
            ["a `x\n   y` b", '<p>a <code>x y</code> b</p>'],
            ["a <!--> `x\ny` -->", '<p>a <!--> <code>x y</code> --&gt;</p>'],
            ["x <y `a\nb` z> w", '<p>x &lt;y <code>a b</code> z&gt; w</p>'],
            ["* a `b\n  c` d\n* e", "<ul>\n  <li>a <code>b c</code> d</li>\n  <li>e</li>\n</ul>"],
            [
                '``
foo
``
', '<p><code>foo</code></p>',
            ],
            [
                '``
foo
bar  
baz
``
', '<p><code>foo bar   baz</code></p>',
            ],
            [
                '``
foo 
``
', '<p><code>foo </code></p>',
            ],
            [
                '`foo   bar 
baz`
', '<p><code>foo   bar  baz</code></p>',
            ],
            [
                '`code  
span`
', '<p><code>code   span</code></p>',
            ],
            [
                '`code\\
span`
', '<p><code>code\\ span</code></p>',
            ],
            [
                'a `  
  ` b', '<p>a <code>   </code> b</p>',
            ],
            [
                '<span title="`a
b`">x</span>', '<p><span title="`a
b`">x</span></p>',
            ],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new MarkdownToCarve())->convert($source)), "\n"));
        }
    }

    public function testInlineHtmlCommentsKeepFollowingTextInConversionMode(): void
    {
        foreach (['<!-->', '<!--->', '<!-- c -->'] as $comment) {
            $converted = (new MarkdownToCarve(convertRawHtml: true))->convert('a ' . $comment . ' b');
            $this->assertSame('<p>a  b</p>', rtrim((new CarveConverter())->convert($converted), "\n"));
        }
    }

    public function testSpansCanStartOnListContinuationLines(): void
    {
        foreach (["- a\n  `b\n  c`", "- a\n  - `b\n    c`", "1. a\n   1. `b\n      c`", "- a\n  - b\n    - `b\n      c`"] as $source) {
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertStringContainsString('<code>b c</code>', (new CarveConverter())->convert($converted));
        }
    }
}
