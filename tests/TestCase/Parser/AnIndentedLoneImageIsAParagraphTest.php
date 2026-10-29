<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R7 promotes a resolved lone-image paragraph at every source column.
 * Tree assertions catch promotion errors that can be hidden by identical HTML.
 */
class AnIndentedLoneImageIsAParagraphTest extends TestCase
{
    use RuleATodo;

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
    public function testAnIndentedLoneImagePromotesToABlockImage(string $indent): void
    {
        $source = $indent . "![a](u)\n";
        $document = $this->parse($source);
        $this->assertSame(['image'], $this->kinds($document));
        $this->assertInstanceOf(Image::class, $document->getChildren()[0]);
    }

    #[DataProvider('indentProvider')]
    public function testTheHtmlIsUnchangedAtEveryIndent(string $indent): void
    {
        // Source indentation does not change the resolved image's HTML.
        $this->assertSame(
            $this->html("![a](u)\n"),
            $this->html($indent . "![a](u)\n"),
        );
    }

    public function testTheReferenceSpellingFoldsTheSameWay(): void
    {
        // Reference images reach the promotion pass after resolution.
        $source = " ![a][r]\n\n[r]: u\n";
        $this->assertSame(
            ['image', 'link_reference_definition'],
            $this->kinds($this->parse($source)),
        );
        $this->assertSame('<img src="u" alt="a">', $this->html($source));

        // Its flush-left twin has the same resolved tree.
        $this->assertSame(
            ['image', 'link_reference_definition'],
            $this->kinds($this->parse("![a][r]\n\n[r]: u\n")),
        );
    }

    public function testAQuoteKeepsBlockImagesAtBothColumns(): void
    {
        // A quote changes the source column but not the resolved image status.
        $atColumn = $this->parse("> ![a](u)\n");
        $pastColumn = $this->parse(">  ![a](u)\n");
        $this->assertSame(['image'], $this->kinds($atColumn->getChildren()[0]));
        $this->assertSame(['image'], $this->kinds($pastColumn->getChildren()[0]));

        // Both images keep the expanded quote layout.
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
        // A caption line is additional content, so this is still a paragraph.
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
        // A line break inside alt text does not change the promotion decision.
        $source = "![a\nb](/i)\n";
        $this->assertSame(['image'], $this->kinds($this->parse($source)));
        $this->assertSame("<img src=\"/i\" alt=\"a\nb\">", $this->html($source));

        $this->assertSame(['image'], $this->kinds($this->parse(" ![a\nb](/i)\n")));
    }

    public function testAnUnresolvedReferenceImageIsNotPromotedAtAnyColumn(): void
    {
        // An unresolved image has no resolved image node to promote.
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
