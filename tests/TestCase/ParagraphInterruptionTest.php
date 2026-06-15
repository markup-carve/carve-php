<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Node\Block\BlockQuote;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Div;
use Carve\Node\Block\Heading;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\Paragraph;
use Carve\Parser\BlockParser;
use Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for default paragraph interruption behavior.
 */
class ParagraphInterruptionTest extends TestCase
{
    public function testBulletDoesNotInterruptParagraph(): void
    {
        // Symmetric interruption: a bullet needs a blank line before it, exactly
        // like an ordered marker. Without the blank line the bullet folds into
        // the open paragraph (lazy continuation).
        $parser = new BlockParser();
        $doc = $parser->parse("Here is a list:\n- item one\n- item two");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testBlockquoteInterruptsParagraph(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testIndentedBulletDoesNotInterruptParagraph(): void
    {
        // Symmetric interruption: an indented bullet no longer interrupts an open
        // paragraph (the former "Rule B" interrupt is gone). It folds in, exactly
        // like an indented ordered marker already did.
        $parser = new BlockParser();
        $doc = $parser->parse("text\n  - item");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testTabIndentedBulletDoesNotInterruptParagraph(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text\n\t- item");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testTopLevelIndentedBulletIsAList(): void
    {
        // An indented bullet with no preceding paragraph opens a list whose base
        // column is the indentation (Rule B).
        $parser = new BlockParser();
        $doc = $parser->parse("  - a\n  - b");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(ListBlock::class, $children[0]);
        $this->assertCount(2, $children[0]->getChildren());
    }

    public function testIndentedOrderedMarkerStillDoesNotInterrupt(): void
    {
        // Ordered markers never interrupt a paragraph (they need a blank line),
        // regardless of indentation.
        $parser = new BlockParser();
        $doc = $parser->parse("text\n  1. item");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testOrderedListDoesNotInterruptParagraph(): void
    {
        // An ordered-list marker does not interrupt a paragraph; it needs a
        // blank line (matching Djot, avoiding the CommonMark `1.`-only heuristic).
        $parser = new BlockParser();
        $doc = $parser->parse("Steps:\n1. First\n2. Second");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testCodeFenceInterruptsParagraphWhenClosed(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Code:\n```\necho hello\n```");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(CodeBlock::class, $children[1]);
    }

    public function testUnterminatedCodeFenceDoesNotInterruptParagraph(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Code:\n```\necho hello");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testDivInterruptsParagraphWhenClosed(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Note:\n::: warning\nImportant\n:::");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Div::class, $children[1]);
    }

    public function testUnterminatedDivDoesNotInterruptParagraph(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Note:\n::: warning\nImportant");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testNestedListWithoutBlankLine(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- Fruits\n  - Apples\n  - Bananas\n- Vegetables");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertCount(2, $list->getChildren());

        $firstItem = $list->getChildren()[0];
        $children = $firstItem->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testConverterFoldsBulletIntoParagraphByDefault(): void
    {
        // Symmetric interruption: a bullet right after a prose line folds into
        // the paragraph (no <ul>); a blank line is required to start the list.
        $converter = new CarveConverter();

        $result = $converter->convert("Here is a list:\n- item one\n- item two");

        $this->assertStringNotContainsString('<ul>', $result);
        $this->assertStringContainsString('Here is a list:', $result);

        $withBlank = $converter->convert("Here is a list:\n\n- item one\n- item two");
        $this->assertStringContainsString('<p>Here is a list:</p>', $withBlank);
        $this->assertStringContainsString('<ul>', $withBlank);
    }

    public function testConverterSoftBreaksWithExplicitBreakMode(): void
    {
        $converter = new CarveConverter(
            softBreakMode: SoftBreakMode::Break,
        );

        $result = $converter->convert("Line one\nLine two");

        $this->assertStringContainsString('<br>', $result);
    }

    public function testEscapedListMarkerNotAList(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Text:\n\\- not a list");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHeadingInterruptsParagraph(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Text\n# Heading");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Heading::class, $children[1]);
    }

    public function testYearDoesNotBecomeList(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("My favorite year was\n1985. It was great.");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHighNumberedListAfterBlankLine(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Continue from step\n\n5. Do this thing");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testNonTablePipeLineStaysProse(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Das berechnet a\n| b als bitweises Oder.");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }
}
