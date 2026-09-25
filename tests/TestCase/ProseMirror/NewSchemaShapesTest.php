<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NewSchemaShapesTest extends TestCase
{
    public function testDirectiveSeparatesKindFromAuthoredClass(): void
    {
        $directive = new Div();
        $directive->setTyped(true);
        $directive->setAttribute('class', 'toc compact');
        $directive->setAttribute('id', 'contents');
        $document = new Document();
        $document->appendChild($directive);

        $pm = (new ProseMirrorRenderer())->render($document);
        $this->assertSame('carveDirective', $pm['content'][0]['type']);
        $this->assertSame('toc', $pm['content'][0]['attrs']['kind']);
        $this->assertSame('compact', $pm['content'][0]['attrs']['class']);

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0];
        $this->assertInstanceOf(Div::class, $back);
        $this->assertSame('toc', $back->directiveKind());
        $this->assertSame(['toc', 'compact'], $back->getClassList());
        $this->assertSame('contents', $back->getAttribute('id'));
    }

    public function testDirectiveOmitsAnOrderSlotForItsKindWord(): void
    {
        $directive = new Div();
        $directive->setTyped(true);
        $directive->setAttribute('class', 'toc');
        $directive->setAttributeOrder(['.class']);
        $document = new Document();
        $document->appendChild($directive);

        $attrs = (new ProseMirrorRenderer())->render($document)['content'][0]['attrs'];
        $this->assertSame('toc', $attrs['kind']);
        $this->assertArrayNotHasKey('class', $attrs);
        $this->assertArrayNotHasKey('carveAttrOrder', $attrs);
    }

    public function testBlockExtensionKeepsIdentityPayloadAndFallback(): void
    {
        $fallback = new Paragraph();
        $fallback->appendChild(new Text('readable'));
        $extension = new BlockExtension('org.example.diagram', $fallback, '2');
        $extension->setPayload(['format' => 'json', 'value' => ['type' => 'swimlane']]);
        $extension->setAttribute('id', 'diagram');
        $document = new Document();
        $document->appendChild($extension);

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render($document);
        $this->assertSame([], $renderer->degradedTypes());
        $this->assertSame('carveBlockExtension', $pm['content'][0]['type']);
        $this->assertSame('paragraph', $pm['content'][0]['content'][0]['type']);
        $this->assertSame(['format' => 'json', 'value' => ['type' => 'swimlane']], $pm['content'][0]['attrs']['payload']);

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0];
        $this->assertInstanceOf(BlockExtension::class, $back);
        $this->assertSame('org.example.diagram', $back->getName());
        $this->assertSame('2', $back->getVersion());
        $this->assertSame('readable', $back->getFallback()->getChildren()[0]->getContent());
        $this->assertSame($extension->getPayload(), $back->getPayload());
        $this->assertSame('diagram', $back->getAttribute('id'));
    }

    public function testRubyPairsRemainEditableInlineArrays(): void
    {
        $ruby = new Ruby([['base' => [new Text('漢')], 'annotation' => [new Text('kan')]]]);
        $ruby->setAttribute('class', 'reading');
        $paragraph = new Paragraph();
        $paragraph->appendChild($ruby);
        $document = new Document();
        $document->appendChild($paragraph);

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render($document);
        $this->assertSame([], $renderer->degradedTypes());
        $wire = $pm['content'][0]['content'][0];
        $this->assertSame('carveRuby', $wire['type']);
        $this->assertSame([['base' => [['type' => 'text', 'text' => '漢']], 'annotation' => [['type' => 'text', 'text' => 'kan']]]], $wire['attrs']['pairs']);
        $this->assertArrayNotHasKey('content', $wire);

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(Ruby::class, $back);
        $this->assertSame('漢', $back->getPairs()[0]['base'][0]->getContent());
        $this->assertSame('kan', $back->getPairs()[0]['annotation'][0]->getContent());
        $this->assertSame('reading', $back->getAttribute('class'));
    }

    public function testRubyMergesSplitMarksWithinEachPairSide(): void
    {
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('b'));
        $strong = new Strong();
        $strong->appendChild(new Text('a '));
        $strong->appendChild($emphasis);
        $ruby = new Ruby([['base' => [$strong], 'annotation' => []]]);
        $paragraph = new Paragraph();
        $paragraph->appendChild($ruby);
        $document = new Document();
        $document->appendChild($paragraph);

        $pm = (new ProseMirrorRenderer())->render($document);
        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(Ruby::class, $back);
        $this->assertCount(1, $back->getPairs()[0]['base']);
        $this->assertInstanceOf(Strong::class, $back->getPairs()[0]['base'][0]);
        $this->assertCount(2, $back->getPairs()[0]['base'][0]->getChildren());
    }

    public function testSmallCapsUsesMarkAndSpanForAuthoredAttributes(): void
    {
        $smallCaps = new SmallCaps();
        $smallCaps->setAttribute('class', 'custom');
        $smallCaps->appendChild(new Text('Example'));
        $paragraph = new Paragraph();
        $paragraph->appendChild($smallCaps);
        $document = new Document();
        $document->appendChild($paragraph);

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render($document);
        $this->assertArrayHasKey('small_caps', $renderer->degradedTypes());
        $marks = $pm['content'][0]['content'][0]['marks'];
        $this->assertSame(['carveSpan', 'carveSmallCaps'], array_column($marks, 'type'));
        $this->assertSame('custom', $marks[0]['attrs']['class']);

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(Span::class, $back);
        $this->assertSame('custom', $back->getAttribute('class'));
        $this->assertInstanceOf(SmallCaps::class, $back->getChildren()[0]);
    }

    public function testRubyRejectsContentOutsidePairs(): void
    {
        $this->expectException(RuntimeException::class);
        (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'carveRuby',
                            'attrs' => ['pairs' => [['base' => [['type' => 'text', 'text' => 'a']], 'annotation' => []]]],
                            'content' => [['type' => 'text', 'text' => 'lost otherwise']],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testBlockExtensionRequiresOneFallback(): void
    {
        $this->expectException(RuntimeException::class);
        (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [['type' => 'carveBlockExtension', 'attrs' => ['name' => 'org.example.diagram']]],
        ]);
    }

    public function testBlockExtensionRejectsPayloadWithoutFormat(): void
    {
        $this->expectException(RuntimeException::class);
        (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveBlockExtension',
                    'attrs' => ['name' => 'org.example.diagram', 'payload' => ['value' => 1]],
                    'content' => [['type' => 'paragraph']],
                ],
            ],
        ]);
    }

    public function testRegisteredFactoryCanOverrideBlockExtension(): void
    {
        $converter = new ProseMirrorToCarve();
        $converter->register('carveBlockExtension', static fn (array $data): Paragraph => new Paragraph());
        $back = $converter->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveBlockExtension',
                    'content' => [['type' => 'text', 'text' => 'custom']],
                ],
            ],
        ])->getChildren()[0];

        $this->assertInstanceOf(Paragraph::class, $back);
        $this->assertSame('custom', $back->getChildren()[0]->getContent());
    }
}
