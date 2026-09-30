<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownEmphasisFidelityTest extends TestCase
{
    public function testInlineMeaning(): void
    {
        $cases = [
            ['x*a \\[b* c]', '<p>x<em>a [b</em> c]</p>'],
            ['x*a [*b*](/u)*', '<p>x<em>a <a href="/u"><em>b</em></a></em></p>'],
            ['x**a [**b**](/u)**', '<p>x<strong>a <a href="/u"><strong>b</strong></a></strong></p>'],
            ['*foo [**x *bar* y**](/u)*', '<p><em>foo <a href="/u"><strong>x <em>bar</em> y</strong></a></em></p>'],
            ['**foo [*x **bar** y*](/u)**', '<p><strong>foo <a href="/u"><em>x <strong>bar</strong> y</em></a></strong></p>'],
            ['*foo [*bar*](/url)*', '<p><em>foo <a href="/url"><em>bar</em></a></em></p>'],
            ['**foo [**bar**](/url)**', '<p><strong>foo <a href="/url"><strong>bar</strong></a></strong></p>'],
            ["[a\nb](/u \"t\nx\")", "<p><a href=\"/u\" title=\"t\nx\">a\nb</a></p>"],
            ["> [a\n> b](/u \"t\n> x\")", "<blockquote><p><a href=\"/u\" title=\"t\nx\">a\nb</a></p></blockquote>"],

            ['![&quot;alt&quot;](/i)', '<img src="/i" alt="&quot;alt&quot;">'],
            ["[l](/u \"t\nx\")", "<p><a href=\"/u\" title=\"t\nx\">l</a></p>"],
            ["![a](/i \"t\nx\")", "<img src=\"/i\" alt=\"a\" title=\"t\nx\">"],
            ['[a](/u?q=&quot;x&quot;)', '<p><a href="/u?q=%22x%22">a</a></p>'],
            ["> *foo\n> bar*", "<blockquote><p><em>foo\nbar</em></p></blockquote>"],
            ["- *foo\n  bar*", "<ul>\n  <li><em>foo\nbar</em></li>\n</ul>"],
            ["1. *foo\n   bar*", "<ol>\n  <li><em>foo\nbar</em></li>\n</ol>"],
            ["> [l](/u \"t\n> x\")", "<blockquote><p><a href=\"/u\" title=\"t\nx\">l</a></p></blockquote>"],
            ["- [l](/u \"t\n  x\")", "<ul>\n  <li><a href=\"/u\" title=\"t\nx\">l</a></li>\n</ul>"],
            ["[foo]: /url 'title\n\ntext'", "<p>[foo]: /url 'title</p>\n<p>text'</p>"],

            ['a*"foo"*', '<p>a*"foo"*</p>'],
            ['&quot;quoted&quot; and &#39;text&#39;', '<p>"quoted" and \'text\'</p>'],
            ['"hello" and \'goodbye\'', '<p>"hello" and \'goodbye\'</p>'],
            ['don\'t change "quotes"', '<p>don\'t change "quotes"</p>'],
            ['**foo "*bar*" foo**', '<p><strong>foo "<em>bar</em>" foo</strong></p>'],
            ['*foo **bar *baz* bim** bop*', '<p><em>foo <strong>bar <em>baz</em> bim</strong> bop</em></p>'],
            ['***outer _inner_ end***', '<p><em><strong>outer <em>inner</em> end</strong></em></p>'],
            ['foo __*__', '<p>foo <strong>*</strong></p>'],
            ['`"code"` and ["label"](/url "title")', '<p><code>"code"</code> and <a href="/url" title="title">"label"</a></p>'],
            ['<span title="quoted">"text"</span>', '<p><span title="quoted">"text"</span></p>'],
            ["*start\n    # heading\nend*", "<p><em>start\n# heading\nend</em></p>"],
            ["*start\n    ---\nend*", "<p><em>start\n---\nend</em></p>"],
            ["*foo\nbar*", "<p><em>foo\nbar</em></p>"],
            ["**foo *bar **baz**\nbim* bop**", "<p><strong>foo <em>bar <strong>baz</strong>\nbim</em> bop</strong></p>"],
            ["*foo  \nbar*", "<p><em>foo<br>\nbar</em></p>"],
            ["*foo\n\nbar*", "<p>*foo</p>\n<p>bar*</p>"],
            ["**foo\n# bar**", "<p>**foo</p>\n<section id=\"bar\">\n  <h1>bar**</h1>\n</section>"],
            ["*foo\n```\nbar*\n```", "<p>*foo</p>\n<pre><code>bar*\n</code></pre>"],
        ];
        foreach ($cases as [$source, $expected]) {
            $written = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($written), "\n"), $source);
        }
    }

    public function testForcedClosersRespectBracketAndDestinationBounds(): void
    {
        $cases = [
            ['{/a [x /}](/u) b/}', '<p><em>a <a href="/u">x /}</a> b</em></p>'],
            ['{/a [x](/u/}) b/}', '<p><em>a <a href="/u/}">x</a> b</em></p>'],
            ['{/a \\[/} b]/}', '<p><em>a [</em> b]/}</p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"), $source);
        }
    }

    public function testQuotesWithAttributesEnabled(): void
    {
        $source = '"hello" \'x\' *word*{title="two words"}';
        $written = (new MarkdownToCarve(convertAttributes: true))->convert($source);
        $this->assertSame('<p>"hello" \'x\' <em title="two words">word</em></p>', rtrim((new CarveConverter())->convert($written), "\n"));
    }

    public function testFootnoteEmphasisSpansLines(): void
    {
        $written = (new MarkdownToCarve())->convert("a[^1]\n\n[^1]: *foo\n    bar*");
        $this->assertStringContainsString("<em>foo\nbar</em>", (new CarveConverter())->convert($written));
    }

    public function testUnescapedInnerQuoteDoesNotBecomeALinkTitle(): void
    {
        $written = (new MarkdownToCarve())->convert('[link](/url "title "and" title")');
        $this->assertStringNotContainsString('<a ', (new CarveConverter())->convert($written));
    }

    public function testRawHtmlRetainsItsSpaces(): void
    {
        foreach (["<span title=\"x  \ny\">b</span>", "<!-- x  \ny -->"] as $html) {
            $written = (new MarkdownToCarve())->convert('*a ' . $html . "\nc*");
            $this->assertStringContainsString(str_replace('>b</span>', '>', $html), $written);
            $this->assertStringNotContainsString("x\\\ny", $written);
        }
    }

    public function testUnattachedAttributesRemainText(): void
    {
        $source = '"a" {.c title="x y"} \'b\'';
        $written = (new MarkdownToCarve(convertAttributes: true))->convert($source);
        $this->assertSame('<p>"a" {.c title="x y"} \'b\'</p>', rtrim((new CarveConverter())->convert($written), "\n"));
    }
}
