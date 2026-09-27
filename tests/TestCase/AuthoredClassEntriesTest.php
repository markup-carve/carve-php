<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthoredClassEntriesTest extends TestCase
{
    public static function values(): array
    {
        return [
            ['.b class=a', ['b', 'a'], 'b a'],
            ['class=a class=b', ['a', 'b'], 'a b'],
            ['class', [''], ''],
            ['class="" .b', ['', 'b'], 'b'],
            ['class="javascript:alert(1)" .b', ['javascript:alert(1)', 'b'], 'b'],
            ['.b class="javascript:alert(1)"', ['b', 'javascript:alert(1)'], 'b'],
            ['class="java script:alert(1)" .b', ['java script:alert(1)', 'b'], 'b'],
            ['class="java script:alert(1)"', ['java script:alert(1)'], ''],
            ['class="a  b" .c', ['a  b', 'c'], 'a  b c'],
            ['class=" a " .b', [' a ', 'b'], ' a  b'],
            ['class="a -col" .a', ['a -col', 'a'], 'a -col a'],
            ['.a .a class="a  b"', ['a', 'a', 'a  b'], 'a a  b'],
        ];
    }

    #[DataProvider('values')]
    public function testEntriesSurviveEveryBridge(string $attributes, array $entries, string $rendered): void
    {
        $source = '{' . $attributes . "}\nx\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        self::assertSame($entries, $document->getChildren()[0]->getClassList());
        $expected = '<p class="' . $rendered . "\">x</p>\n";
        self::assertSame($expected, $converter->render($document));

        $codec = new AstCodec();
        $wire = json_decode(json_encode($codec->encode($document), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($entries, $wire['children'][0]['attrs']['classes']);
        $decoded = $codec->decode($wire);
        self::assertSame($entries, $decoded->getChildren()[0]->getClassList());
        self::assertSame($expected, $converter->render($decoded));

        $pm = (new ProseMirrorRenderer())->render($document);
        self::assertSame($entries, $pm['content'][0]['attrs']['class']);
        $restored = (new ProseMirrorToCarve())->convert($pm);
        self::assertSame($entries, $restored->getChildren()[0]->getClassList());
        self::assertSame($expected, $converter->render($restored));

        $writer = CarveConverter::carve();
        $written = $writer->render($document);
        self::assertSame($written, $writer->convert($written));
        self::assertSame($entries, $converter->parse($written)->getChildren()[0]->getClassList());
    }

    public function testAttributeTransfersKeepAuthoredBoundaries(): void
    {
        $attributes = '{class="javascript:alert(1)" class="a  b" .c}';
        foreach (
            [
                "%s\n:::\nx\n:::\n",
                "%s\n::: note\nx\n:::\n",
                "[x]%s\n",
                "-%s x\n",
                "| x |%s\n",
                "%s\n> x\n",
                "%s\n![x](image.png)\n",
                "[x][r]\n\n[r]: /url %s\n",
            ] as $surface
        ) {
            $source = sprintf($surface, $attributes);
            $converter = new CarveConverter();
            $document = $converter->parse($source);
            $html = $converter->render($document);
            self::assertStringContainsString('a  b c', $html, $source);
            self::assertStringNotContainsString('javascript:', $html, $source);
            $written = CarveConverter::carve()->render($document);
            self::assertSame($html, $converter->convert($written), $source);
            $pm = (new ProseMirrorRenderer())->render($document);
            self::assertSame($html, $converter->render((new ProseMirrorToCarve())->convert($pm)), $source);
        }
    }

    public function testImportedClassesChooseTheirOwnSpelling(): void
    {
        $source = (new HtmlToCarve())->convert('<div class="-col a"><p>x</p></div>');
        self::assertStringContainsString('{class="-col" .a}', $source);
    }

    public function testExtensionsKeepWhitespaceAndFilterEntries(): void
    {
        foreach ([new DetailsExtension(), new TabsExtension()] as $extension) {
            $converter = new CarveConverter();
            $converter->addExtension($extension);
            $kind = $extension instanceof DetailsExtension ? 'details' : 'tabs';
            $source = '{class="java script:alert(1)" class="a  b" .c}' . "\n::: " . $kind . "\nx\n:::\n";
            $html = $converter->convert($source);
            self::assertStringContainsString('a  b c', $html);
            self::assertStringNotContainsString('script:', $html);
        }
    }

    public function testRoundTripHtmlWritesEachClassEntrySpellably(): void
    {
        $attributes = '{class="a  b" .c class=""}';
        foreach (
            [
                $attributes . "\n``` php\nx\n```\n",
                $attributes . "\n::: code-group\n``` php\nx\n```\n:::\n",
                ":::: tabs\n::: tab\n" . $attributes . "\n::: div\nx\n:::\n:::\n::::\n",
            ] as $source
        ) {
            $renderer = new HtmlRenderer();
            $renderer->setRoundTripMode(true);
            $converter = CarveConverter::create(renderer: $renderer);
            $converter->addExtension(new TabsExtension());
            $converter->addExtension(new CodeGroupExtension());
            $html = $converter->convert($source);
            self::assertSame(1, preg_match('/data-djot-src="([^"]*)"/', $html, $match));
            $restored = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringContainsString($attributes, $restored);
        }
    }

    public function testClassMutationKeepsTheListAndStringViewsTogether(): void
    {
        $node = new Paragraph();
        $node->appendClass('a  b');
        $node->addClass('c');
        $node->addClass('c');
        self::assertSame(['a  b', 'c'], $node->getClassList());
        self::assertSame('a  b c', $node->getAttribute('class'));
        $node->setAttributesWithOrder(['class' => ['', 'd']], ['.class']);
        self::assertSame(['', 'd'], $node->getClassList());
        $node->removeAttribute('class');
        self::assertSame([], $node->getClassList());
        self::assertNull($node->getAttribute('class'));
    }
}
