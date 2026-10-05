<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\ProseMirror\SchemaMap;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class LinearFootnotesAndAttributesTest extends TestCase
{
    public function testChainedAndCyclicFootnotesKeepDiscoveryOrder(): void
    {
        $converter = CarveConverter::create();
        $html = $converter->convert("[^a] [^c]\n\n[^a]: a [^b]\n\n[^b]: b [^a]\n\n[^c]: c [^d]\n\n[^d]: d\n");
        $this->assertSame(4, substr_count($html, '<li id="fn'));
        $this->assertStringContainsString('<li id="fn3"', $html);
        $this->assertStringContainsString('<li id="fn4"', $html);
        $this->assertStringContainsString('fnref1-2', $html);
        $this->assertSame("<p>plain</p>\n", $converter->convert('plain'));
    }

    public function testLongChainRendersEveryNoteOnce(): void
    {
        $source = "[^n0]\n\n";
        for ($i = 0; $i < 256; $i++) {
            $source .= '[^n' . $i . ']: note' . ($i < 255 ? ' [^n' . ($i + 1) . ']' : '') . "\n\n";
        }
        $html = CarveConverter::create()->convert($source);
        $this->assertSame(256, substr_count($html, '<li id="fn'));
        $this->assertStringContainsString('<li id="fn256"', $html);
    }

    public function testAttributeOrderReplacementKeepsFirstSlotAndLastValue(): void
    {
        $node = new Span();
        $node->setAttribute('a', 'old');
        $node->setAttributeOrder(['b']);
        $node->setAttribute('a', 'new');
        $node->setAttribute('b', 'value');
        $this->assertSame(['b', 'a'], $node->getAttributeOrder());
        $node->mergeLeadingAttributes(['c' => 'leading', 'a' => 'leading'], ['c', 'a']);
        $node->setAttribute('d', 'last');
        $this->assertSame(['c', 'a', 'b', 'd'], $node->getAttributeOrder());
        $this->assertSame('new', $node->getAttribute('a'));
    }

    public function testLargeAbbreviationDictionaryStillExpandsMatchingWords(): void
    {
        $source = '';
        for ($i = 0; $i < 8192; $i++) {
            $source .= '*[A' . $i . "]: expansion\n";
        }
        $source .= "\nA8191 A0 AX A0B\n";
        $html = CarveConverter::create()->convert($source);
        $this->assertStringContainsString('<abbr title="expansion">A8191</abbr>', $html);
        $this->assertStringContainsString('<abbr title="expansion">A0</abbr>', $html);
        $this->assertSame(2, substr_count($html, '<abbr'));
        $this->assertStringContainsString('A0B', $html);
    }

    public function testFootnoteHooksMayMoveThePublicNumberingArrayCursor(): void
    {
        $renderer = new class extends HtmlRenderer {
            protected function renderFootnoteRef(FootnoteRef $node): string
            {
                reset($this->getRenderContext()->footnoteNumbers);

                return parent::renderFootnoteRef($node);
            }
        };
        $html = CarveConverter::create(renderer: $renderer)->convert("[^a]\n\n[^a]: a [^b]\n\n[^b]: b [^c]\n\n[^c]: c\n");
        $this->assertSame(3, substr_count($html, '<li id="fn'));
    }

    public function testAttributeSlotCachesDoNotChangeMarkIdentity(): void
    {
        $authored = new Span();
        $authored->setAttribute('k', 'v');
        $synthesized = new Span();
        $synthesized->setSynthesizedAttribute('k', 'v');
        $this->assertTrue(SchemaMap::isSameMark($authored, $synthesized));
    }

    public function testCustomNodesMayReplaceProtectedAttributeOrder(): void
    {
        $node = new class extends Span {
            public function replaceOrder(array $order): void
            {
                $this->attributeOrder = $order;
            }
        };
        $node->setAttribute('a', 'old');
        $node->replaceOrder(['b']);
        $node->setAttribute('a', 'new');
        $node->setAttribute('b', 'value');
        $this->assertSame(['b', 'a'], $node->getAttributeOrder());
    }
}
