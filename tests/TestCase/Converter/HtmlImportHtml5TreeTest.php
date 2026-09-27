<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMElement;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\HtmlImportDepthExceededException;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

#[RequiresPhp('>=8.4.0')]
class HtmlImportHtml5TreeTest extends TestCase
{
    public function testHtmlNamesOutsideXmlRemainReadableAndSerializable(): void
    {
        $html = '<form><x@y q@r="v">hello</x@y></form>';
        $document = HtmlDomLoader::fragment($html);
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        self::assertSame($html, HtmlDomLoader::serialize($form));
        self::assertStringContainsString($html, (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
        self::assertSame("hello\n", (new HtmlToCarve())->convert('<x@y>hello</x@y>'));
    }

    public function testNameEscapingDoesNotRewriteAuthoredText(): void
    {
        $html = '<x@y>CARVE-N-784079 &#67;ARVE-N-784079</x@y>';
        $document = HtmlDomLoader::fragment($html);
        self::assertSame('CARVE-N-784079 CARVE-N-784079', $document->documentElement?->textContent);
        self::assertSame('<carve-import-root><x@y>CARVE-N-784079 CARVE-N-784079</x@y></carve-import-root>', HtmlDomLoader::serialize($document->documentElement));
    }

    public function testOrdinaryNestingAndRawTextRemainReadable(): void
    {
        $document = HtmlDomLoader::fragment(str_repeat('<ul><li>', 200) . 'content');
        self::assertSame('content', $document->documentElement?->textContent);
        $text = str_repeat('<div>', 8000);
        $document = HtmlDomLoader::fragment('<textarea>' . $text . '</textarea>');
        self::assertSame($text, $document->getElementsByTagName('textarea')->item(0)?->textContent);
    }

    public function testImportedForeignElementsKeepTheirLocalNames(): void
    {
        $document = HtmlDomLoader::fragment('<math alttext="x"><mi>x</mi></math><svg><title>y</title></svg>');
        foreach (['math', 'mi', 'svg', 'title'] as $tag) {
            self::assertSame($tag, $document->getElementsByTagName($tag)->item(0)?->localName);
        }
        self::assertSame('x', $document->getElementsByTagName('math')->item(0)?->getAttribute('alttext'));
    }

    public function testAnEmptyFragmentKeepsAnEmptyRoot(): void
    {
        $document = HtmlDomLoader::fragment('');
        self::assertSame('carve-import-root', $document->documentElement?->tagName);
        self::assertSame('', $document->documentElement?->textContent);
    }

    public function testAnUnclosedAnchorIsReconstructedInTheNextListItem(): void
    {
        $html = '<ul><li><a id="target">one<li>two</ul>';
        $document = HtmlDomLoader::load($html);
        $anchors = $document->getElementsByTagName('a');
        self::assertCount(2, $anchors);
        foreach ($anchors as $anchor) {
            self::assertSame('target', $anchor->getAttribute('id'));
            self::assertSame('li', $anchor->parentNode?->nodeName);
        }
        self::assertSame("- [one]{#target}\n- [two]{#target}\n", (new HtmlToCarve())->convert($html));
    }

    public function testFormattingIsReconstructedAfterAMisnestedClose(): void
    {
        $html = '<p><b>one<i>two</b>three</i></p>';
        self::assertSame("*one{/two/}*/three/\n", (new HtmlToCarve())->convert($html));
    }

    public function testTextInATableMovesBeforeTheTable(): void
    {
        $html = '<table>before<tr><td>cell</td></tr>after</table>';
        self::assertSame("beforeafter\n\n| cell |\n", (new HtmlToCarve())->convert($html));
    }

    public function testRawHtmlKeepsLeadingNewlinesWhenReparsed(): void
    {
        foreach (['pre', 'textarea', 'listing'] as $tag) {
            $html = '<form><' . $tag . ">\n\ny</" . $tag . '></form>';
            $document = HtmlDomLoader::fragment($html);
            $before = $document->getElementsByTagName($tag)->item(0)?->textContent;
            $form = $document->getElementsByTagName('form')->item(0);
            self::assertInstanceOf(DOMElement::class, $form);
            $serialized = HtmlDomLoader::serialize($form);
            $reread = HtmlDomLoader::fragment($serialized);
            self::assertSame("\ny", $before);
            self::assertSame($before, $reread->getElementsByTagName($tag)->item(0)?->textContent);
            self::assertSame($before, $document->getElementsByTagName($tag)->item(0)?->textContent);
            self::assertStringContainsString($serialized, (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
        }
    }

    public function testCommentsOutsideHtmlStayInTheImport(): void
    {
        $html = '<!doctype html><!-- before --><html><body><p>a</p></body></html><!-- after -->';
        $carve = (new HtmlToCarve())->convert($html);
        self::assertStringContainsString('before', $carve);
        self::assertStringContainsString('after', $carve);
        self::assertLessThan(strpos($carve, 'after'), strpos($carve, 'before'));
    }

    public function testDocumentDetectionIgnoresCustomElementPrefixes(): void
    {
        self::assertFalse(HtmlDomLoader::isDocument('<bodyguard>x</bodyguard>'));
        self::assertFalse(HtmlDomLoader::isDocument('<html-card>x</html-card>'));
        self::assertTrue(HtmlDomLoader::isDocument('<!-- before --><html><body>x</body></html>'));
    }

    public function testEmptyBooleanAttributeValuesStayEmpty(): void
    {
        $document = HtmlDomLoader::fragment('<form><input checked=""></form>');
        $input = $document->getElementsByTagName('input')->item(0);
        self::assertInstanceOf(DOMElement::class, $input);
        self::assertSame('', $input->getAttribute('checked'));
        self::assertSame('<input checked="">', HtmlDomLoader::serialize($input));
    }

    public function testTemplateContentsRemainTraversable(): void
    {
        $document = HtmlDomLoader::load('<template><p>x</p><template><b>y</b></template></template>');
        $template = $document->getElementsByTagName('template')->item(0);
        self::assertInstanceOf(DOMElement::class, $template);
        self::assertSame('xy', $template->textContent);
        self::assertCount(2, $template->childNodes);
        self::assertSame('<template><p>x</p><template><b>y</b></template></template>', HtmlDomLoader::serialize($template));
    }

    public function testFragmentContextKeepsRowsAndNestedTemplatesAfterStrayClosingTags(): void
    {
        $document = HtmlDomLoader::fragment('</template><tr><td>a</td></tr><template><p>x</p><template><b>y</b></template></template>');
        self::assertSame('a', $document->getElementsByTagName('td')->item(0)?->textContent);
        $template = $document->getElementsByTagName('template')->item(0);
        self::assertInstanceOf(DOMElement::class, $template);
        self::assertSame('<template><p>x</p><template><b>y</b></template></template>', HtmlDomLoader::serialize($template));
    }

    public function testFragmentWhitespaceSurvivesTextAttributesCommentsAndRawText(): void
    {
        $whitespace = "\n\f\t\f \f\f\n\n";
        $document = HtmlDomLoader::fragment('<div title="' . $whitespace . '">' . $whitespace . '<!--' . $whitespace . '--><script>' . $whitespace . '</script></div><textarea>&#13;z</textarea>');
        $div = $document->getElementsByTagName('div')->item(0);
        self::assertInstanceOf(DOMElement::class, $div);
        self::assertSame($whitespace, $div->getAttribute('title'));
        self::assertSame($whitespace, $div->firstChild?->nodeValue);
        self::assertSame($whitespace, $div->childNodes->item(1)?->nodeValue);
        self::assertSame($whitespace, $document->getElementsByTagName('script')->item(0)?->textContent);
        self::assertSame("\rz", $document->getElementsByTagName('textarea')->item(0)?->textContent);
    }

    public function testPlaintextKeepsAuthoredClosingTagsWithoutSerializerClosers(): void
    {
        $text = 'a</plaintext></div><p>b';
        $document = HtmlDomLoader::fragment('<div><plaintext>' . $text);
        self::assertSame($text, $document->getElementsByTagName('plaintext')->item(0)?->textContent);
    }

    public function testNativeSerializationKeepsRawTextVoidElementsAndUrls(): void
    {
        foreach (['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes'] as $tag) {
            $html = '<' . $tag . '><b>&</' . $tag . '>';
            $document = HtmlDomLoader::fragment($html);
            $element = $document->getElementsByTagName($tag)->item(0);
            self::assertInstanceOf(DOMElement::class, $element);
            self::assertSame($html, HtmlDomLoader::serialize($element));
            self::assertSame('<b>&', HtmlDomLoader::serialize($element->firstChild));
        }
        $html = '<form><source src="é a"><wbr><a href="é a">x</a></form>';
        $document = HtmlDomLoader::fragment($html);
        self::assertSame($html, HtmlDomLoader::serialize($document->getElementsByTagName('form')->item(0)));
    }

    public function testNamespaceAttributesAndAuthoredPlaceholderNamesRemainVisible(): void
    {
        $html = '<CARVE-N-784079 xmlns="urn:x" xmlns:q="urn:q" @click="a">CARVE-N-784079</CARVE-N-784079>';
        $document = HtmlDomLoader::fragment($html);
        $element = $document->documentElement?->firstChild;
        self::assertInstanceOf(DOMElement::class, $element);
        self::assertCount(3, $element->attributes);
        self::assertSame('<carve-n-784079 xmlns="urn:x" xmlns:q="urn:q" @click="a">CARVE-N-784079</carve-n-784079>', HtmlDomLoader::serialize($element));
    }

    public function testExcessiveTreeDepthHasATypedFailure(): void
    {
        $this->expectException(HtmlImportDepthExceededException::class);
        HtmlDomLoader::fragment(str_repeat('<div>', 513) . 'deep');
    }
}
