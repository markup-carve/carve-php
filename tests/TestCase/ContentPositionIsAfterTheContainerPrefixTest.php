<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A LINE'S CONTENT POSITION IS AFTER ITS CONTAINER PREFIX (PART 11 §8b M2b,
 * the ruling on markup-carve/carve#1330).
 *
 * M2b keeps an authored hash escaped where it would open an ATX heading and
 * emits it bare everywhere else. The position was measured on the FINISHED
 * document, so a container prefix defeated it: the hash at the start of a
 * quote's content had a `> ` in front of it, scored as mid-line, and lost the
 * escape. Read back through an importer, `> \\# heading` came out as
 * `<blockquote><h1>heading</h1></blockquote>` - the author's text returned as
 * structure, which is corruption rather than a rendering difference.
 *
 * The position is now measured on the EMITTED LINE, after every prefix the
 * writer put in front of the content, to whatever depth and in whatever
 * combination.
 *
 * BOTH DIRECTIONS ARE ASSERTED ON EVERY CHARACTER, because the failure mode of
 * a correction like this is widening it into "an escape behind a prefix is
 * kept". It is not: a hash mid-line loses its escape inside a container just as
 * it does outside one, and so does a hash at the content position whose run is
 * closed by a letter, since M2b's reading is CommonMark's and neither of those
 * opens a heading.
 *
 * The corpus pins the quote and the bullet
 * (`343-an-escaped-hash-keeps-its-escape-at-a-container-s-content-position`).
 * What is here is what the corpus does not reach: the prefixes it does not
 * spell, the non-container that must NOT count, and the alignment case that
 * says why the position cannot be recovered from the finished document.
 */
class ContentPositionIsAfterTheContainerPrefixTest extends TestCase
{
    protected function md(string $source): string
    {
        return trim((new MarkdownRenderer())->render((new CarveConverter())->parse($source)));
    }

    public function testKeepsTheEscapeAtAQuoteABulletAndBothNested(): void
    {
        $this->assertSame('> \\# heading', $this->md('> \\# heading'));
        $this->assertSame('- \\# heading', $this->md('- \\# heading'));
        $this->assertSame('> > \\# deep', $this->md('> > \\# deep'));
        $this->assertSame('- - \\# deep', $this->md('- - \\# deep'));
    }

    public function testKeepsItBehindATaskMarkerAndAnOrderedMarker(): void
    {
        $this->assertSame('- [ ] \\# heading', $this->md('- [ ] \\# heading'));
        $this->assertSame('- [x] \\# heading', $this->md('- [x] \\# heading'));
        $this->assertSame('1. \\# heading', $this->md('1. \\# heading'));
    }

    public function testKeepsItBehindAFootnoteDefinitionMarker(): void
    {
        // The definition body is a block like any other, so its marker is a
        // prefix like any other. Asserted on the line rather than on the whole
        // document because the numbering and the reference come with it.
        $this->assertStringContainsString(
            '[^n]: \\# heading',
            $this->md("a[^n]\n\n[^n]: \\# heading"),
        );
    }

    public function testKeepsItBehindADefinitionMarker(): void
    {
        $this->assertStringContainsString(': \\# heading', $this->md(":: term\n:  \\# heading"));
        // And the narrowing behind that marker too: a run closed by a letter
        // opens no heading there any more than anywhere else.
        $this->assertStringContainsString(': #tag rest', $this->md(":: term\n:  \\#tag rest"));
    }

    public function testKeepsItOnALazyContinuationWhichTheWriterReprefixes(): void
    {
        // Lazy continuation is a PARSER concept: a line inside a container that
        // does not carry its marker. This writer emits no such line, so the
        // second line arrives at M2b with its `> ` and is read at the content
        // position like any other.
        $this->assertSame("> a\n> \\# heading", $this->md("> a\n\\# heading"));
    }

    public function testKeepsItUnderTheAlignmentAWideMarkerGivesAContinuationLine(): void
    {
        // THE CASE THAT SAYS WHY THE POSITION CANNOT BE DERIVED FROM THE
        // FINISHED DOCUMENT. §10 aligns a continuation line to the marker's
        // width, so this one carries four spaces - and four spaces is an
        // over-indent to anything that does not already know the marker above
        // it was `10. `. Only the writer knows, which is why the decision is
        // taken where it writes the line.
        $this->assertSame("10. a\n\n    \\# heading", $this->md("10. a\n\n    \\# heading"));
    }

    public function testDropsItMidLineInsideAContainerExactlyAsOutsideOne(): void
    {
        $this->assertSame('> C# is a language', $this->md('> C\\# is a language'));
        $this->assertSame('- issue #123 fixed', $this->md('- issue \\#123 fixed'));
        $this->assertSame('C# is a language', $this->md('C\\# is a language'));
    }

    public function testDropsItAtTheContentPositionWhenTheRunOpensNoHeading(): void
    {
        // A run closed by a letter is not a heading under CommonMark, and a run
        // longer than six is not one either. Standing at the content position
        // is necessary and not sufficient.
        $this->assertSame('> #tag rest', $this->md('> \\#tag rest'));
        $this->assertSame('- #tag rest', $this->md('- \\#tag rest'));
        $this->assertSame('> ####### too many', $this->md('> \\#\\#\\#\\#\\#\\#\\# too many'));
        $this->assertSame('> \\###### six is fine', $this->md('> \\#\\#\\#\\#\\#\\# six is fine'));
    }

    public function testDropsItBehindAHeadingMarkerWhichIsNotAContainerPrefix(): void
    {
        // A heading is not a container and `## ` is part of the heading's own
        // line, so the hash behind it is mid-line. CommonMark reads `## # x` as
        // an h2 whose text is `# x`, so the escape protects nothing and goes.
        $this->assertSame('## # x', $this->md('## \\# x'));
        $this->assertSame('# # x', $this->md('# \\# x'));
    }

    public function testDropsItInATableCellWhereThePipeIsNotAPrefixEither(): void
    {
        $this->assertStringContainsString(
            '| # x |',
            $this->md("| \\# x | y |\n|---|---|\n| a | b |"),
        );
    }

    public function testLeavesAHashAtColumnZeroExactlyWhereItWas(): void
    {
        // The rule that was already right, and the one a correction to the
        // others is most likely to disturb. Column 0 is the content position of
        // a line no container encloses.
        $this->assertSame('\\# heading', $this->md('\\# heading'));
        $this->assertSame('#tag rest', $this->md('\\#tag rest'));
    }

    public function testKeepsANestedQuotesHashWhenTheOuterQuoteHasOneOfItsOwn(): void
    {
        // THE CASE THE SECOND SENTINEL EXISTS FOR. The inner quote answers M2b
        // on its own content and the outer one answers it on content that
        // already carries the inner marker. Recorded as a distinct sentinel the
        // inner answer is inert to that second pass; left undecided it is
        // measured again against `> # deep`, scores as mid-line, and the outer
        // marker takes the escape straight back off.
        //
        // The outer quote must carry a hash of ITS OWN, or it skips the pass
        // entirely and the case proves nothing.
        $this->assertSame(
            "> \\# outer\n>\n> > \\# deep",
            $this->md("> \\# outer\n>\n> > \\# deep"),
        );
    }

    public function testReadsAHashTheTrimMovesToColumnZeroAsBeingAtColumnZero(): void
    {
        // A BLOCK DOES NOT KNOW WHETHER ITS OWN LEADING WHITESPACE SURVIVES.
        // Four spaces in front of the hash stay where the paragraph sits
        // mid-document and are trimmed away where it is the first block of the
        // document or of a container. Deciding before that trim scored the hash
        // as over-indented and emitted it bare, and the trim then put the bare
        // hash at column 0 - a heading where the author wrote text, which is
        // the same corruption this clause exists to prevent, arriving from the
        // other direction.
        //
        // Hand-built: the parser does not keep leading whitespace on a
        // paragraph, and an ingested tree is a document this target has to
        // render correctly.
        foreach (['    ', "\t", '  '] as $lead) {
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text($lead));
            $paragraph->appendChild(new EscapedText('#'));
            $paragraph->appendChild(new Text(' heading'));
            $document = new Document();
            $document->appendChild($paragraph);

            $this->assertSame("\\# heading\n", (new MarkdownRenderer())->render($document));
        }
    }

    public function testDoesTheSameForTheFirstBlockInsideAContainer(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('    '));
        $paragraph->appendChild(new EscapedText('#'));
        $paragraph->appendChild(new Text(' heading'));
        $quote = new BlockQuote();
        $quote->appendChild($paragraph);
        $document = new Document();
        $document->appendChild($quote);

        $this->assertSame("> \\# heading\n", (new MarkdownRenderer())->render($document));
    }
}
