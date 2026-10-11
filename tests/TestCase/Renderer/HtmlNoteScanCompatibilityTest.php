<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HtmlNoteScanCompatibilityTest extends TestCase
{
    public function testCustomNoteHooksStillInspectDescendants(): void
    {
        $renderer = new class extends HtmlRenderer {
            public function detects(Node $node): bool
            {
                return $this->holdsANote($node);
            }

            protected function holdsANote(Node $node): bool
            {
                return $node->hasClass('custom-note') || parent::holdsANote($node);
            }
        };
        $document = new Document();
        $span = new Span();
        $span->addClass('custom-note');
        $document->appendChild($span);
        $this->assertTrue($renderer->detects($document));
    }

    public function testTheFirstReachableNoteStopsTheScan(): void
    {
        $renderer = new class extends HtmlRenderer {
            public function detects(Node $node): bool
            {
                return $this->holdsANote($node);
            }
        };
        $document = new Document();
        $document->appendChild(new FootnoteRef('note'));
        $document->appendChild(new class extends Span {
            /**
             * @throws \RuntimeException
             *
             * @return array<\MarkupCarve\Carve\Node\Node>
             */
            public function getChildren(): array
            {
                throw new RuntimeException('The scan passed the first reachable note.');
            }
        });
        $this->assertTrue($renderer->detects($document));
    }
}
