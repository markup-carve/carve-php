<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

class UpstreamWireContractTest extends TestCase
{
    public function testSubstitutionHalvesKeepInlineMarks(): void
    {
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('old'));
        $substitution = new Substitution();
        $substitution->getOld()->appendChild($emphasis);
        $substitution->getNew()->appendChild(new Text('new'));
        $paragraph = new Paragraph();
        $paragraph->appendChild($substitution);
        $document = new Document();
        $document->appendChild($paragraph);

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render($document);
        $attrs = $pm['content'][0]['content'][0]['attrs'];
        $this->assertSame([['type' => 'text', 'text' => 'old', 'marks' => [['type' => 'italic']]]], $attrs['old']);
        $this->assertSame([['type' => 'text', 'text' => 'new']], $attrs['new']);
        $this->assertSame([], $renderer->degradedTypes());

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(Substitution::class, $back);
        $this->assertInstanceOf(Emphasis::class, $back->getOld()->getChildren()[0]);
        $this->assertSame('old', $back->getOldText());
        $this->assertSame('new', $back->getNewText());
    }

    public function testBlockPositionSurvivesTheEditor(): void
    {
        $paragraph = new Paragraph();
        $span = new SourceSpan(2, 2, 1, 5, 10, 14, 'included.crv');
        $paragraph->setPos($span);
        $paragraph->appendChild(new Text('text'));
        $document = new Document();
        $document->appendChild($paragraph);

        $pm = (new ProseMirrorRenderer())->render($document);
        $this->assertSame($span->toWireArray(), $pm['content'][0]['attrs']['carvePos']);
        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0];
        $this->assertSame($span->toWireArray(), $back->getPos()?->toWireArray());
        $this->assertNull($back->getAttribute('carvePos'));
    }

    public function testInheritedColumnAlignmentIsPresentationOnly(): void
    {
        $table = new Table();
        $row = new TableRow();
        $cell = new TableCell(false, 'right', 1, 1, null, false);
        $cell->appendChild(new Text('value'));
        $row->appendChild($cell);
        $table->appendChild($row);
        $document = new Document();
        $document->appendChild($table);

        $pm = (new ProseMirrorRenderer())->render($document);
        $attrs = $pm['content'][0]['content'][0]['content'][0]['attrs'];
        $this->assertSame('right', $attrs['carveInheritedTextAlign']);
        $this->assertArrayNotHasKey('textAlign', $attrs);

        $back = (new ProseMirrorToCarve())->convert($pm)->getChildren()[0]->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(TableCell::class, $back);
        $this->assertFalse($back->hasExplicitAlignment());
        $this->assertNull($back->getAttribute('carveInheritedTextAlign'));
    }

    public function testParsedDelimiterAlignmentReachesBodyCells(): void
    {
        $document = (new CarveConverter())->parse("| h |\n|--:|\n| a |\n");
        $pm = (new ProseMirrorRenderer())->render($document);

        $attrs = $pm['content'][0]['content'][1]['content'][0]['attrs'];
        $this->assertSame('right', $attrs['carveInheritedTextAlign']);
        $this->assertArrayNotHasKey('textAlign', $attrs);
    }

    public function testInvalidPositionIsReportedWithoutDiscardingContent(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'attrs' => ['carvePos' => ['startLine' => 1]],
                    'content' => [['type' => 'text', 'text' => 'kept']],
                ],
            ],
        ]);

        $this->assertSame('kept', $document->getChildren()[0]->getChildren()[0]->getContent());
        $this->assertNull($document->getChildren()[0]->getPos());
        $this->assertArrayHasKey('carvePos', $converter->droppedAttributes());
    }

    public function testCaptionDefaultShortFlagDoesNotBecomeAnAuthoredAttribute(): void
    {
        $document = (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveCaption',
                    'attrs' => ['short' => false],
                    'content' => [['type' => 'text', 'text' => 'caption']],
                ],
            ],
        ]);

        $caption = $document->getChildren()[0];
        $this->assertNull($caption->getAttribute('short'));
    }
}
