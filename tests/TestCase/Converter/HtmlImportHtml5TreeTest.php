<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMElement;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\HtmlImportDepthExceededException;
use PHPUnit\Framework\TestCase;

class HtmlImportHtml5TreeTest extends TestCase
{
    public function testDeepHtmlIsRefusedDuringTreeConstruction(): void
    {
        $this->expectException(HtmlImportDepthExceededException::class);
        HtmlDomLoader::fragment(str_repeat('<div>', 8000));
    }

    public function testOrdinaryNestingAndRawTextRemainReadable(): void
    {
        $document = HtmlDomLoader::fragment(str_repeat('<ul><li>', 200) . 'content');
        self::assertSame('content', $document->documentElement?->textContent);
        $text = str_repeat('<div>', 8000);
        $document = HtmlDomLoader::fragment('<textarea>' . $text . '</textarea>');
        self::assertSame($text, $document->getElementsByTagName('textarea')->item(0)?->textContent);
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
}
