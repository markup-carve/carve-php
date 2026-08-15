<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §4c (markup-carve/carve#1122): a BARE `::: figure` opener - the fence,
 * its separator, and the kind word, nothing else - ALWAYS produces a
 * `figure_group`, whatever the body holds. An opener carrying a quoted title
 * or a `[label]` does not match the production and stays a generic Tier-2
 * container; a bare figure opener anywhere inside an open group's body stays a
 * generic container too, because groups do not nest.
 *
 * The corpus (318-composite-figures*) pins the HTML bytes; this file pins the
 * TREE decisions, which the corpus can only see through the renderer.
 */
class ABareFigureFenceIsACompositeFigureTest extends TestCase
{
    protected function parseFirst(string $source): object
    {
        $children = (new CarveConverter())->parse($source)->getChildren();
        $this->assertNotSame([], $children);

        return $children[0];
    }

    public function testABareOpenerProducesAFigureGroup(): void
    {
        $node = $this->parseFirst("::: figure\n![one](a.png)\n^ (a) One\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertSame('figure_group', $node->getType());
    }

    public function testAZeroPanelGroupIsAValidParse(): void
    {
        // Degenerate counts are lint findings, not parse errors (§4c).
        $node = $this->parseFirst("::: figure\nJust prose.\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertSame([], $node->getPanels());
        $this->assertCount(1, $node->getChildren(), 'the stray paragraph is preserved in place');
    }

    public function testAnEmptyGroupIsAValidParseToo(): void
    {
        $node = $this->parseFirst("::: figure\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertSame([], $node->getChildren());
    }

    public function testAQuotedTitleKeepsTheOpenerAGenericContainer(): void
    {
        // `figure_group` has no title slot BY DESIGN (§4c): the group's one
        // authored metadata channel is the caption, and a second spelling for
        // "the text above the figure" would claim carve#1121's design space.
        $node = $this->parseFirst("::: figure \"T\"\nBody.\n:::\n");

        $this->assertInstanceOf(Div::class, $node);
        $this->assertSame('T', $node->getHeader());
    }

    public function testALabelKeepsTheOpenerAGenericContainer(): void
    {
        $node = $this->parseFirst("::: figure [g]\nBody.\n:::\n");

        $this->assertInstanceOf(Div::class, $node);
        $this->assertSame('g', $node->getLabel());
    }

    public function testGroupsDoNotNest(): void
    {
        // The inner bare figure opener - ANY depth inside an open group's
        // body - is a generic Tier-2 container, not an inner group.
        $node = $this->parseFirst("::: figure\n:::: figure\n![one](a.png)\n^ (a) One\n::::\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $inner = $node->getChildren()[0];
        $this->assertInstanceOf(Div::class, $inner);
    }

    public function testABareFigureBelowAnotherContainerInsideTheGroupStaysGenericToo(): void
    {
        // "Anywhere inside an open group's body -- any depth" (§4c): the
        // nesting rule is not limited to direct children.
        $node = $this->parseFirst("::: figure\n:::: note\n::::: figure\nDeep.\n:::::\n::::\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $note = $node->getChildren()[0];
        $this->assertInstanceOf(Div::class, $note);
        $deep = $note->getChildren()[0];
        $this->assertInstanceOf(Div::class, $deep, 'a bare figure at depth two inside a group must not open a group');
    }

    public function testABareFigureInsideAnOrdinaryContainerIsStillAGroup(): void
    {
        // The no-nesting rule is about GROUPS: a note admonition is not one,
        // so a bare figure inside it opens a composite figure normally.
        $node = $this->parseFirst("::: note\n:::: figure\n![one](a.png)\n^ (a) One\n::::\n:::\n");

        $this->assertInstanceOf(Div::class, $node);
        $this->assertInstanceOf(FigureGroup::class, $node->getChildren()[0]);
    }

    public function testTheCaptionAfterTheCloserIsTheGroupCaption(): void
    {
        $node = $this->parseFirst("::: figure\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: Group\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertTrue($node->hasCaption());
        $this->assertCount(1, $node->getPanels(), 'the inner caption stays on its local panel host');
    }

    public function testOneBlankLineStillAttachesTheGroupCaption(): void
    {
        $node = $this->parseFirst("::: figure\n![one](a.png)\n^ (a) One\n:::\n\n^ Figure #: Group\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertTrue($node->hasCaption());
    }

    public function testTwoBlankLinesDetachTheGroupCaption(): void
    {
        // The shared caption_slot allowance (PART 2), corpus
        // 318-composite-figures-6: the detached line is an ordinary paragraph.
        $document = (new CarveConverter())->parse("::: figure\n![one](a.png)\n^ (a) One\n:::\n\n\n^ Figure #: Detached\n");

        $group = $document->getChildren()[0];
        $this->assertInstanceOf(FigureGroup::class, $group);
        $this->assertFalse($group->hasCaption());
        $this->assertCount(2, $document->getChildren());
    }

    public function testASecondCaptionLineDoesNotReplaceTheGroupCaption(): void
    {
        // The rule the table already has (carve-php#1199): the second `^ `
        // line has no captionable block left and is ordinary paragraph text.
        $html = (new CarveConverter())->convert("::: figure\n![one](a.png)\n:::\n^ First\n^ Second\n");

        $this->assertStringContainsString('<figcaption>First</figcaption>', $html);
        $this->assertStringContainsString('<p>^ Second</p>', $html);
    }

    public function testACaretAfterAnyOtherContainerCloserStaysAParagraph(): void
    {
        // Only kind `figure` grew the caption slot (§4c); corpus
        // 318-composite-figures-7 pins the note case.
        $html = (new CarveConverter())->convert("::: note\nBody.\n:::\n^ Not a caption\n");

        $this->assertStringContainsString('<p>^ Not a caption</p>', $html);
        $this->assertStringNotContainsString('figcaption', $html);
    }

    public function testPanelsAreTheCaptionableDirectChildrenInSourceOrder(): void
    {
        // A table is a panel captioned or not; an image paragraph needs its
        // caption to become a figure; loose prose is content, not a panel.
        $node = $this->parseFirst(
            "::: figure\nProse between.\n\n| a |\n|---|\n\n![one](a.png)\n^ (a) One\n:::\n",
        );

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertCount(3, $node->getChildren());
        $panels = $node->getPanels();
        $this->assertCount(2, $panels);
        $this->assertSame('table', $panels[0]->getType());
        $this->assertSame('figure', $panels[1]->getType());
    }

    public function testAPrecedingAttributeLineLandsOnTheGroup(): void
    {
        $node = $this->parseFirst("{#fig-x .columns-2}\n::: figure\n![one](a.png)\n^ (a) One\n:::\n");

        $this->assertInstanceOf(FigureGroup::class, $node);
        $this->assertSame('fig-x', $node->getAttribute('id'));
        $this->assertSame('columns-2', $node->getAttribute('class'));
    }
}
