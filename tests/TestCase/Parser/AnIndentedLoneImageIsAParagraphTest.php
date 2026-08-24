<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1660: an indented LONE image is a paragraph holding an
 * inline image, not a block image.
 *
 * PART 9 section 15's strict column-0 rule says a top-level block opener must
 * start at column 0, with the worked example rendering an indented heading
 * marker as literal text. A block image is a top-level block construct, so an
 * indented one cannot be one - the leading space cannot be inert for an image
 * and decisive for a heading. This engine and carve-rs read it the other way;
 * the ruling moved them, against the engine split two to one.
 *
 * WHAT MAKES THESE FIXTURES ABLE TO FAIL is the shape they use, and it is the
 * whole lesson of the ticket: a lone indented image with NO CONTINUATION LINE.
 * `promoteBlockImageAttributes()` only fires on a paragraph whose entire content
 * is one image, so every pinned document that indents an image - all three of
 * corpus `158-indented-image-and-caption-stay-literal` - carries a caption line
 * that keeps it from firing at all. Three engines held two readings with every
 * gate green because no fixture anywhere had one line.
 *
 * AND THE HTML CANNOT SEE IT, which is why these assert on the TREE.
 * `HtmlRenderer::isBlockImageParagraph()` renders a surviving sole-image
 * paragraph as a bare `<img>` with no `<p>` wrapper at every column, so both
 * readings emit the same bytes. Each case below therefore asserts the tree AND
 * the unchanged HTML: a fix that started emitting `<p><img></p>` would be a
 * different bug, and the HTML halves are what catch it.
 */
class AnIndentedLoneImageIsAParagraphTest extends TestCase
{
    private function parse(string $source): Document
    {
        return (new CarveConverter())->parse($source);
    }

    private function html(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return list<string>
     */
    private function kinds(Node $node): array
    {
        return array_values(array_map(
            static fn (Node $child): string => $child->getType(),
            $node->getChildren(),
        ));
    }

    public function testAFlushLeftLoneImageIsABlockImage(): void
    {
        // The control. Without it a reader that stopped promoting anything at
        // all passes every other case in this file.
        $this->assertSame(['image'], $this->kinds($this->parse("![a](u)\n")));
        $this->assertSame('<img src="u" alt="a">', $this->html("![a](u)\n"));
    }

    /**
     * @return list<array{string}>
     */
    public static function indentProvider(): array
    {
        // One space is the boundary and three is the width the ticket measured;
        // a fix keyed to a particular width passes one and fails the others.
        return [[' '], ['  '], ['   '], ["\t"]];
    }

    #[DataProvider('indentProvider')]
    public function testAnIndentedLoneImageIsAParagraphHoldingAnInlineImage(string $indent): void
    {
        $source = $indent . "![a](u)\n";
        $document = $this->parse($source);
        $this->assertSame(['paragraph'], $this->kinds($document));

        $paragraph = $document->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $children = $paragraph->getChildren();
        $this->assertCount(1, $children);
        // The image has to be INLINE inside it. A paragraph holding something
        // else would satisfy the type assertion and mean nothing.
        $this->assertInstanceOf(Image::class, $children[0]);
    }

    #[DataProvider('indentProvider')]
    public function testTheHtmlIsUnchangedAtEveryIndent(string $indent): void
    {
        // Stated as an assertion rather than a comment: it is the reason the
        // corpus could not catch this, and it is the half a tree-only fix
        // silently breaks.
        $this->assertSame(
            $this->html("![a](u)\n"),
            $this->html($indent . "![a](u)\n"),
        );
    }

    public function testTheReferenceSpellingFoldsTheSameWay(): void
    {
        // A reference image is never a syntactic block image: it arrives as a
        // paragraph and is promoted afterwards or not at all, so it reaches the
        // same pass by a different route. A fix applied only to the direct
        // spelling would leave this one promoting.
        $source = " ![a][r]\n\n[r]: u\n";
        $this->assertSame(
            ['paragraph', 'link_reference_definition'],
            $this->kinds($this->parse($source)),
        );
        $this->assertSame('<img src="u" alt="a">', $this->html($source));

        // Its flush-left twin still promotes, which is what makes the row above
        // a measurement rather than a statement that references never promote.
        $this->assertSame(
            ['image', 'link_reference_definition'],
            $this->kinds($this->parse("![a][r]\n\n[r]: u\n")),
        );
    }

    public function testTheColumnIsTheContainersContentColumnNotColumnZero(): void
    {
        // A quote body one past its content column folds exactly as a top-level
        // indented image does. A fix that tested the literal source column would
        // pass everything above and fail here.
        $atColumn = $this->parse("> ![a](u)\n");
        $pastColumn = $this->parse(">  ![a](u)\n");
        $this->assertSame(['image'], $this->kinds($atColumn->getChildren()[0]));
        $this->assertSame(['paragraph'], $this->kinds($pastColumn->getChildren()[0]));

        // And the quote keeps the EXPANDED layout for both, rather than the
        // compact single-paragraph form a surviving paragraph would otherwise
        // take.
        $this->assertSame($this->html("> ![a](u)\n"), $this->html(">  ![a](u)\n"));
        $this->assertSame(
            "<blockquote>\n  <img src=\"u\" alt=\"a\">\n</blockquote>",
            $this->html(">  ![a](u)\n"),
        );
    }

    public function testAListItemsPaddingIsAbsorbedByItsContentColumn(): void
    {
        // The near miss in the other direction. A list marker's content column
        // takes the padding, so an item's lead paragraph BEGINS at that column
        // however wide the padding is - the image still promotes. A fix that
        // measured raw leading whitespace instead of the column would break
        // every list item holding an image, which is the shape carve-rs#610
        // broke once already for the caption half of the same pass.
        foreach ([1, 2, 3, 5] as $width) {
            $source = '-' . str_repeat(' ', $width) . "![a](u)\n";
            $list = $this->parse($source)->getChildren()[0];
            $item = $list->getChildren()[0];
            $this->assertSame(['image'], $this->kinds($item), "padding width {$width}");
        }
    }

    public function testACaptionLineStillKeepsTheIndentedPairLiteral(): void
    {
        // Corpus `158-indented-image-and-caption-stay-literal`, restated as the
        // control that bounds the change: an indented image with a caption was
        // already literal paragraph text and still is, so the new gate did not
        // reach past the shape it is about.
        $source = " ![Apollo](a.jpg)\n ^ Figure 1: moon\n";
        $this->assertSame(['paragraph'], $this->kinds($this->parse($source)));
        $this->assertSame(
            "<p><img src=\"a.jpg\" alt=\"Apollo\">\n^ Figure 1: moon</p>",
            $this->html($source),
        );

        // Its flush-left twin is a figure.
        $this->assertSame(
            ['figure'],
            $this->kinds($this->parse("![Apollo](a.jpg)\n^ Figure 1: moon\n")),
        );
    }

    public function testAnAltTextCrossingALineBoundaryStillPromotesAtColumnZero(): void
    {
        // THE NEAR MISS, and it is a real one: a first version of this fix read
        // the paragraph's leading whitespace at the CONSTRUCTION site, after the
        // fold loop had reassigned the variables it was reading - so it answered
        // for whichever line the paragraph stopped on. Corpus
        // `351-a-bracketed-construct-spanning-a-line-boundary-4` is one image
        // whose alt text crosses a line boundary, and it stopped promoting.
        //
        // A fixture holding only indented images cannot see that: every one of
        // them is one line, so the first line and the last line are the same
        // line and a fix reading the wrong one still passes.
        $source = "![a\nb](/i)\n";
        $this->assertSame(['image'], $this->kinds($this->parse($source)));
        $this->assertSame("<img src=\"/i\" alt=\"a\nb\">", $this->html($source));

        // And the same document indented folds, which is what makes the row
        // above a measurement of the FIRST line rather than of line count.
        $this->assertSame(['paragraph'], $this->kinds($this->parse(" ![a\nb](/i)\n")));
    }

    public function testAnUnresolvedReferenceImageIsNotPromotedAtAnyColumn(): void
    {
        // The pre-existing carve-out, asserted so the new gate cannot be read as
        // the only reason a paragraph survives here.
        foreach (["![a][missing]\n", " ![a][missing]\n"] as $source) {
            $this->assertSame(['paragraph'], $this->kinds($this->parse($source)), $source);
        }
    }

    public function testAnImageSharingItsRunIsStillAParagraphAtEveryColumn(): void
    {
        foreach (["![a](u) t\n", " ![a](u) t\n"] as $source) {
            $this->assertSame(['paragraph'], $this->kinds($this->parse($source)), $source);
        }
    }
}
