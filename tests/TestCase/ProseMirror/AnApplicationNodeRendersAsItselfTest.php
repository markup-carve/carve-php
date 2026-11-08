<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use Closure;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4947: `ProseMirrorRenderer::register()` is the way back for nodes an
 * application registered on `ProseMirrorToCarve`.
 */
class AnApplicationNodeRendersAsItselfTest extends TestCase
{
    public function testAnInlineAtomComesBackAsItsOwnNode(): void
    {
        $doc = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'placeholderToken', 'attrs' => ['label' => 'Projektname', 'key' => 'project.name']],
                        ['type' => 'text', 'text' => ' und '],
                        ['type' => 'text', 'text' => 'frei', 'marks' => [['type' => 'italic']]],
                    ],
                ],
            ],
        ];

        // The converter copies the node's attrs onto the returned span itself.
        $converter = (new ProseMirrorToCarve())->register('placeholderToken', function (): Node {
            $span = new Span();
            $span->addClass('placeholder-token');

            return $span;
        });
        $renderer = $this->placeholderRenderer();

        $this->assertSame($doc, $renderer->render($converter->convert($doc)));
        $this->assertSame([], $renderer->droppedTypes());
        $this->assertSame([], $renderer->degradedTypes());
    }

    public function testAnAtomInsideAMarkReceivesNoMarksUnlessItAsks(): void
    {
        $strong = new Strong();
        $strong->appendChild($this->placeholderSpan());
        $paragraph = new Paragraph();
        $paragraph->appendChild($strong);

        $pm = $this->placeholderRenderer()->render($this->document($paragraph));

        $this->assertSame(
            [['type' => 'placeholderToken', 'attrs' => ['label' => 'Projektname', 'key' => 'project.name']]],
            $pm['content'][0]['content'],
        );
    }

    public function testTwoAbuttingAtomsAreNotReportedAsMerged(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild($this->placeholderSpan());
        $paragraph->appendChild($this->placeholderSpan());
        $renderer = $this->placeholderRenderer();

        $pm = $renderer->render($this->document($paragraph));

        $this->assertCount(2, $pm['content'][0]['content']);
        $this->assertSame([], $renderer->degradedTypes());
    }

    public function testABlockNodeRendersItsChildrenThroughTheCallback(): void
    {
        $inner = new Paragraph();
        $inner->appendChild(new Text('Baustein'));
        $div = new Div();
        $div->addClass('text-block');
        $div->appendChild($inner);

        $renderer = (new ProseMirrorRenderer())->register(
            fn (Node $node): bool => $node instanceof Div && $node->hasClass('text-block'),
            fn (Node $node, Closure $children): array => ['type' => 'textBlock', 'content' => $children()],
        );

        $this->assertSame(
            [['type' => 'textBlock', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Baustein']]]]]],
            $renderer->render($this->document($div))['content'],
        );
    }

    public function testNullLeavesTheNodeOutAndTheFirstMatchWins(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('vorher '));
        $paragraph->appendChild($this->placeholderSpan());

        $renderer = (new ProseMirrorRenderer())
            ->register(fn (Node $node): bool => $node instanceof Span, fn (): ?array => null)
            ->register(fn (Node $node): bool => $node instanceof Span, fn (): array => ['type' => 'unreachable']);

        $this->assertSame(
            [['type' => 'text', 'text' => 'vorher ']],
            $renderer->render($this->document($paragraph))['content'][0]['content'],
        );
    }

    public function testAnUnmatchedSpanKeepsTheBuiltInMapping(): void
    {
        $other = new Span();
        $other->addClass('other');
        $other->appendChild(new Text('x'));
        $paragraph = new Paragraph();
        $paragraph->appendChild($other);

        $pm = $this->placeholderRenderer()->render($this->document($paragraph));

        $this->assertSame('carveSpan', $pm['content'][0]['content'][0]['marks'][0]['type']);
    }

    private function placeholderRenderer(): ProseMirrorRenderer
    {
        return (new ProseMirrorRenderer())->register(
            fn (Node $node): bool => $node instanceof Span && $node->hasClass('placeholder-token'),
            fn (Node $node): array => [
                'type' => 'placeholderToken',
                'attrs' => ['label' => $node->getAttribute('label'), 'key' => $node->getAttribute('key')],
            ],
        );
    }

    private function placeholderSpan(): Span
    {
        $span = new Span();
        $span->addClass('placeholder-token');
        $span->setAttribute('label', 'Projektname');
        $span->setAttribute('key', 'project.name');

        return $span;
    }

    private function document(Node $block): Document
    {
        $document = new Document();
        $document->appendChild($block);

        return $document;
    }
}
