<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownImportFixedPointTest extends TestCase
{
    public function testImportUsesWriterSpellings(): void
    {
        $rows = [
            'cm-414' => ["*foo **bar***\n", "/foo *bar*/\n", "<p><em>foo <strong>bar</strong></em></p>\n"],
            'cm-418' => ["*foo **bar *baz* bim** bop*\n", "{/foo *bar {/baz/} bim* bop/}\n", "<p><em>foo <strong>bar <em>baz</em> bim</strong> bop</em></p>\n"],
            'cm-432' => ["**foo *bar **baz**\nbim* bop**\n", "{*foo /bar {*baz*}\nbim/ bop*}\n", "<p><strong>foo <em>bar <strong>baz</strong>\nbim</em> bop</strong></p>\n"],
            'cm-440' => ["foo **\\***\n", "foo {*\\**}\n", "<p>foo <strong>*</strong></p>\n"],
            'cm-442' => ["**foo*\n", "\\*/foo/\n", "<p>*<em>foo</em></p>\n"],
            'cm-443' => ["*foo**\n", "/foo/\\*\n", "<p><em>foo</em>*</p>\n"],
            'cm-445' => ["****foo*\n", "\\*\\*\\*/foo/\n", "<p>***<em>foo</em></p>\n"],
            'cm-446' => ["**foo***\n", "*foo*\\*\n", "<p><strong>foo</strong>*</p>\n"],
            'cm-447' => ["*foo****\n", "/foo/\\*\\*\\*\n", "<p><em>foo</em>***</p>\n"],
            'co-465-an-underscore-pair-in-text-is-escaped-where-the-line-would-pair-it' => ["*x*\\_y\\_ in company_id and a_b_c and a _ b _ c\n", "{/x/}\\_y\\_ in company_id and a_b_c and a _ b _ c\n", "<p><em>x</em>_y_ in company_id and a_b_c and a _ b _ c</p>\n"],
            'co-468-an-underscore-pair-split-across-a-line-break-is-escaped' => ["*x*\\_y\nz\\_ w\n", "{/x/}\\_y\nz\\_ w\n", "<p><em>x</em>_y\nz_ w</p>\n"],
            'cm-552' => ["[\n ]\n\n[\n ]: /uri\n", "[\n]\n\n[\n]: /uri\n", "<p>[\n]</p>\n<p>[\n]: /uri</p>\n"],
            'label-continuation-tail' => ["[\n ] tail\n", "[\n] tail\n", "<p>[\n] tail</p>\n"],
            'link-label-continuation' => ["[a\n ](/u)\n", "[a\n](/u)\n", "<p><a href=\"/u\">a\n</a></p>\n"],
            'label-continuation-in-paragraph' => ["x [\n ] y\n", "x [\n] y\n", "<p>x [\n] y</p>\n"],
            'open-label-paragraph' => ["x [\n y\n", "x [\ny\n", "<p>x [\ny</p>\n"],
            'open-label-with-text' => ["x [a\n b] y\n", "x [a\nb] y\n", "<p>x [a\nb] y</p>\n"],
            'open-label-at-start' => ["[\n b\n", "[\nb\n", "<p>[\nb</p>\n"],
            'open-label-punctuation' => ["x [\n ) y\n", "x [\n) y\n", "<p>x [\n) y</p>\n"],
            'ordinary-paragraph-punctuation' => ["x (\n ) y\n", "x (\n) y\n", "<p>x (\n) y</p>\n"],
            'ordinary-paragraph-closer' => ["x\n ] y\n", "x\n] y\n", "<p>x\n] y</p>\n"],
            'open-label-tab' => ["x [\n\t] y\n", "x [\n] y\n", "<p>x [\n] y</p>\n"],
            'multiline-reference-label' => ["[a\n b]: /u\n\n[a\n b]\n", "[a\nb]\n", "<p>[a\nb]</p>\n"],
            'list-label-continuation' => ["- [\n  ]\n", "- [\n  ]\n", "<ul>\n  <li>[\n]</li>\n</ul>\n"],
            'quote-label-continuation' => ["> [\n>  ]\n", "> [\n> ]\n", "<blockquote><p>[\n]</p></blockquote>\n"],
            'list-before-quote-link-continuation' => ["- > [link](/url \"title\")\n  > [link](/url 'title')\n", "- > [link](/url \"title\")\n  > [link](/url \"title\")\n", "<ul>\n  <li>\n    <blockquote><p><a href=\"/url\" title=\"title\">link</a>\n<a href=\"/url\" title=\"title\">link</a></p></blockquote>\n  </li>\n</ul>\n"],
            'ordered-list-before-quote-link-continuation' => ["1. > [link](/url \"title\")\n   > [link](/url 'title')\n", "1. > [link](/url \"title\")\n   > [link](/url \"title\")\n", "<ol>\n  <li>\n    <blockquote><p><a href=\"/url\" title=\"title\">link</a>\n<a href=\"/url\" title=\"title\">link</a></p></blockquote>\n  </li>\n</ol>\n"],
            'list-open-label-text' => ["- x [\n   y\n", "- x [\n  y\n", "<ul>\n  <li>x [\ny</li>\n</ul>\n"],
            'quote-open-label-text' => ["> x [\n>  y\n", "> x [\n> y\n", "<blockquote><p>x [\ny</p></blockquote>\n"],
            'emphasis-continuation' => ["*a\n  b*\n", "/a\nb/\n", "<p><em>a\nb</em></p>\n"],
            'emphasis-in-paragraph' => ["x *a\n  b* y\n", "x /a\nb/ y\n", "<p>x <em>a\nb</em> y</p>\n"],
            'strike-continuation' => ["a ~~b\n  c~~\n", "a ~b\nc~\n", "<p>a <s>b\nc</s></p>\n"],
            'list-emphasis-continuation' => ["- *a\n    b*\n", "- /a\n  b/\n", "<ul>\n  <li><em>a\nb</em></li>\n</ul>\n"],
            'quote-emphasis-continuation' => ["> *a\n>   b*\n", "> /a\n> b/\n", "<blockquote><p><em>a\nb</em></p></blockquote>\n"],
            'link-label-indentation' => ["see [foo\n   bar](/u) here\n", "see [foo\nbar](/u) here\n", "<p>see <a href=\"/u\">foo\nbar</a> here</p>\n"],
            'nested-link-label' => ["[a [b\n  c](/u) d](/v)\n", "[a [b\nc](/u) d]\\(/v)\n", "<p>[a <a href=\"/u\">b\nc</a> d](/v)</p>\n"],
            'emphasis-in-link-label' => ["see [*foo\n  bar*](/u) x\n", "see [/foo\nbar/](/u) x\n", "<p>see <a href=\"/u\"><em>foo\nbar</em></a> x</p>\n"],
            'co-45-inline-extensions-11' => ["*[HTML]: Hyper Text Markup Language\n\n<abbr title=\"Custom\">HTML</abbr>\n", "*[HTML]: Hyper Text Markup Language\n\n[HTML]{abbr=Custom}\n", "<p><abbr title=\"Custom\">HTML</abbr></p>\n"],
            'co-175-a-repeated-definition-which-one-wins-2' => ["*[A]: a\n\n*[A]: b\n\n<abbr title=\"b\">A</abbr> here.\n", "*[A]: a\n\n*[A]: b\n\nA here.\n", "<p><abbr title=\"b\">A</abbr> here.</p>\n"],
            'words' => ["*alpha **beta***\n", "/alpha *beta*/\n", "<p><em>alpha <strong>beta</strong></em></p>\n"],
            'underscore' => ["_alpha __beta___\n", "/alpha *beta*/\n", "<p><em>alpha <strong>beta</strong></em></p>\n"],
            'deeper' => ["***one **two *three* four** five***\n", "{/{*one {*two {/three/} four*} five*}/}\n", "<p><em><strong>one <strong>two <em>three</em> four</strong> five</strong></em></p>\n"],
            'escaped-star' => ["*alpha*\\* next\n", "/alpha/\\* next\n", "<p><em>alpha</em>* next</p>\n"],
            'escaped-underscore' => ["*alpha*\\_ next\n", "{/alpha/}\\_ next\n", "<p><em>alpha</em>_ next</p>\n"],
            'escaped-punctuation' => ["*alpha*\\! next\n", "/alpha/\\! next\n", "<p><em>alpha</em>! next</p>\n"],
            'list' => ["- *alpha **beta***\n", "- /alpha *beta*/\n", "<ul>\n  <li><em>alpha <strong>beta</strong></em></li>\n</ul>\n"],
            'quote' => ["> *alpha **beta***\n", "> /alpha *beta*/\n", "<blockquote><p><em>alpha <strong>beta</strong></em></p></blockquote>\n"],
            'code-fence-label-shape' => ["```\n[\n ]\n```\n", "```\n[\n ]\n```\n", "<pre><code>[\n ]\n</code></pre>\n"],
            'inline-code-label-shape' => ["`[\n ]`\n", "`[ ]`\n", "<p><code>[ ]</code></p>\n"],
        ];

        foreach ($rows as $id => [$source, $expected, $html]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($html, (new CarveConverter())->convert($actual), $id);
        }
    }

    public function testRawHtmlTagUsesWriterWhitespace(): void
    {
        $actual = (new MarkdownToCarve())->convert("x <b\n  c> y\n");
        self::assertSame("x `<b\nc>`{=html} y\n", $actual);
        self::assertSame($actual, CarveConverter::toCarve($actual));
        self::assertSame("<p>x <b\nc> y</p>\n", (new CarveConverter())->convert($actual));
    }
}
