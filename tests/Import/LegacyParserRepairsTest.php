<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Import\HtmlNode;
use MarkupCarve\Carve\Import\HtmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * libxml below 2.14 lets HTML5 void elements hold content, keeps bogus comments as text and parses markup inside raw text
 * and RCDATA elements; the fallback repairs all three.
 */
final class LegacyParserRepairsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inputs(): array
    {
        return [
            'wbr' => ['<p>a<wbr>b<wbr>c</p>', 'p[#a,wbr[],#b,wbr[],#c]'],
            'source and track' => [
                '<video><source src="a.mp4"><track src="a.vtt">x</video>',
                'video[source[],track[],#x]',
            ],
            'embed before a block' => ['<div><embed src="a">t<p>u</p></div>', 'div[embed[],#t,p[#u]]'],
            'downlevel-revealed comment' => ['<p>a<![if !x]>b<![endif]>c</p>', 'p[#a,![if !x],#b,![endif],#c]'],
            'bogus comment at the end' => ['<p>a</p><!x', 'p[#a],!x'],
            'markup declaration in an attribute' => ['<p title="<!x>">a</p>', 'p[#a]'],
            'markup declaration in raw text' => ['<textarea><!x></textarea>', 'textarea[#<!x>]'],
            'comment and doctype untouched' => ['<!-- c --><p>a</p>', '! c ,p[#a]'],
            'end tag prefix in a textarea' => ['<textarea>a</textareas>b</textarea>', 'textarea[#a</textareas>b]'],
            'tags and entities in RCDATA' => ['<title>a<b>c</b>&amp;</x></title>', 'title[#a<b>c</b>&</x>]'],
            'tags and entities in raw text' => ['<xmp>a&amp;<i>b</i></xmp>', 'xmp[#a&amp;<i>b</i>]'],
            'end tags in a script' => ['<script>a</b>&amp;&lt;</scripts></script>x', 'script[#a</b>&amp;&lt;</scripts>],#x'],
            'end tag in a style' => ['<style class="k">p{}</x></style>', 'style[#p{}</x>]'],
            'MathML text integration point' => ['<math><mi><textarea>a</b></textarea></mi></math>', 'math[mi[textarea[#a</b>]]]'],
            'MathML closed by an ancestor end tag' => ['<i><math></i><title><!x></title>', 'i[math[]],title[#<!x>]'],
            'marker name in an attribute value' => ['<script data-id="carve-import-raw">a</b></script>', 'script[#a</b>]'],
            'unclosed textarea' => ['<p>a</p><textarea>b</p>c', 'p[#a],textarea[#b</p>c]'],
            'unclosed comment' => ['<p>a</p><!--b', 'p[#a],!b'],
            'SVG title is not RCDATA' => [
                '<svg><title><b>a</b></title></svg><textarea>b</i></textarea>',
                'svg[title[b[#a]]],textarea[#b</i>]',
            ],
            'stray end tag in SVG' => ['<svg><g></x><title><b>a</b></title></svg>', 'svg[g[title[b[#a]]]]'],
            'MathML glyph stays foreign' => [
                '<math><mi><mglyph><textarea>a</b></textarea></mglyph></mi></math>',
                'math[mi[mglyph[textarea[#a]]]]',
            ],
            'SVG style holds markup' => ['<svg><style>a<i>b</i>&amp;</style></svg>', 'svg[style[#a]],i[#b],#&'],
            'SVG script decodes entities' => ['<svg><script>a&lt;b</script>c</svg>', 'svg[script[#a<b],#c]'],
            'raw text names in SVG' => [
                '<svg><g><textarea>a<!--c--></textarea><xmp>&amp;</xmp><iframe>i</iframe></g></svg>',
                'svg[g[textarea[#a,!c],xmp[#&],iframe[#i]]]',
            ],
            'HTML title in an SVG title' => ['<svg><title>a<title>b</title>c</title></svg>', 'svg[title[#a,title[#b],#c]]'],
            'breakout closes SVG' => ['<svg><g><title>t</title><p>a</p></g></svg>b', 'svg[g[title[#t]]],p[#a],#b'],
            'end tag br breaks out' => [
                '<math><mi>a</mi><mo></br>b</mo></math><svg></br x="1">c',
                'math[mi[#a],mo[br[],#b]],svg[],br[],#c',
            ],
            'nested math in SVG stays SVG' => ['<svg><math><desc><b>a</b></desc></math></svg>', 'svg[math[desc[b[#a]]]]'],
            'CDATA in an SVG title' => ['<svg><title><![CDATA[a<b]]><i><![CDATA[c]]></i></title></svg>', 'svg[title[#a<b,i[![CDATA[c]]]]]'],
            'end tag br is a br' => ['<p>a</br>b</BR >c</p>', 'p[#a,br[],#b,br[],#c]'],
            'font with color breaks out' => ['<svg><font color="red">a</font><font x="color">b</font></svg>', 'svg[],font[#a],font[#b]'],
            'CDATA in SVG is text' => ['<svg>a<![CDATA[<b>&amp;]]>c</svg><p><![CDATA[x]]></p>', 'svg[#a<b>&amp;c],p[![CDATA[x]]]'],
            'HTML in annotation-xml' => [
                '<math><annotation-xml encoding="text/html"><p>a</p></annotation-xml></math>',
                'math[annotation-xml[p[#a]]]',
            ],
            'end tag without a name' => ['<p>a</ b>c</>d</</p>e</', 'p[#a,! b,#cd,!</p,#e</]'],
            'end tag p without a paragraph' => ['<div>a</p>b</div><p>c</p></P>', 'div[#a,p[],#b],p[#c],p[]'],
            'end tag p breaks out of SVG' => ['<svg><textarea>a</p>b</textarea></svg>', 'svg[textarea[#a]],p[],#b'],
            'bogus comment hides an end tag in SVG' => ['<svg><title>a</</title>b</svg>', 'svg[title[#a,!</title,#b]]'],
        ];
    }

    #[DataProvider('inputs')]
    public function testLegacyTree(string $html, string $expected): void
    {
        $this->assertSame($expected, self::shape(HtmlParser::parse($html, true)));
    }

    #[DataProvider('inputs')]
    public function testHtml5TreeAgrees(string $html, string $expected): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('only the libxml parser exists on this PHP');
        }
        $this->assertSame($expected, self::shape(HtmlParser::parse($html)));
    }

    /**
     * The libxml tree gets the namespace and the SVG and MathML names the HTML tree builder gives.
     */
    public function testForeignElementsKeepTheirNames(): void
    {
        $html = '<svg viewbox="0 0 1 1"><clippath/><foreignobject><b>a</b></foreignobject></svg><math definitionurl="u"><mi>x</mi></math>';
        foreach ([true, false] as $legacy) {
            if (!$legacy && !HtmlParser::html5()) {
                continue;
            }
            [$svg, $math] = HtmlParser::parse($html, $legacy)->children;
            [$clipPath, $foreignObject] = $svg->children;
            $this->assertSame(['viewBox' => '0 0 1 1'], $svg->attrs);
            $this->assertSame(['clipPath', true], [$clipPath->name, $clipPath->foreign]);
            $this->assertSame(['foreignObject', true], [$foreignObject->name, $foreignObject->foreign]);
            $this->assertFalse($foreignObject->children[0]->foreign);
            $this->assertSame(['definitionURL' => 'u'], $math->attrs);
            $this->assertTrue($math->children[0]->foreign);
        }
    }

    public function testAttributeKeepsTheMarkupDeclaration(): void
    {
        $paragraph = HtmlParser::parse('<p title="<!x>">a</p><!y>', true)->children[0];
        $this->assertSame('<!x>', $paragraph->attr('title'));
    }

    public function testAnEndTagPrefixDoesNotEndRawText(): void
    {
        $textarea = HtmlParser::parse('<textarea>a</textareas><!x></textarea>', true)->children[0];
        $this->assertCount(1, $textarea->children);
        $this->assertStringEndsWith('<!x>', $textarea->children[0]->name);
    }

    public function testEscapedScriptKeepsOnlyItsOwnAttributes(): void
    {
        $script = HtmlParser::parse('<script type="m">a</b></script>', true)->children[0];
        $this->assertSame(['type' => 'm'], $script->attrs);
    }

    public function testAnInputAttributeIsNotTakenForTheMarker(): void
    {
        $scripts = HtmlParser::parse('<script carve-import-raw>&lt;</script><script carve-import-raw="q">a</b></script>', true)->children;
        $this->assertSame(['carve-import-raw' => ''], $scripts[0]->attrs);
        $this->assertSame('&lt;', $scripts[0]->children[0]->name);
        $this->assertSame(['carve-import-raw' => 'q'], $scripts[1]->attrs);
    }

    private static function shape(HtmlNode $node): string
    {
        $parts = [];
        foreach ($node->children as $child) {
            $parts[] = match ($child->kind) {
                HtmlNode::TEXT => '#' . $child->name,
                HtmlNode::COMMENT => '!' . $child->name,
                default => $child->name . '[' . self::shape($child) . ']',
            };
        }

        return implode(',', $parts);
    }
}
