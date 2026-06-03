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
 * Tests for blocks-interrupt-paragraphs mode.
 *
 * This markdown-like mode lets top-level blocks (lists, blockquotes, headings,
 * tables, fences, divs) interrupt a paragraph without a preceding blank line.
 * Nesting blocks inside list items is native Carve and works regardless of this
 * flag. Ideal for chat messages, comments, and quick notes.
 */
class BlocksInterruptParagraphsTest extends TestCase
{
    // ==================== Parser Tests ====================

    public function testDefaultModeIsSpecCompliant(): void
    {
        $parser = new BlockParser();
        $this->assertFalse($parser->getBlocksInterruptParagraphs());
    }

    public function testSetterAndGetter(): void
    {
        $parser = new BlockParser();

        $result = $parser->setBlocksInterruptParagraphs(true);
        $this->assertTrue($parser->getBlocksInterruptParagraphs());
        $this->assertSame($parser, $result); // Fluent interface
    }

    public function testConstructorParameter(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $this->assertTrue($parser->getBlocksInterruptParagraphs());
    }

    // ==================== Paragraph Interruption Tests ====================

    public function testListInterruptsParagraph(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Here is a list:\n- item one\n- item two");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testBlockquoteInterruptsParagraph(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testOrderedListInterruptsParagraph(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Steps:\n1. First\n2. Second");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testCodeFenceInterruptsParagraph(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Code:\n```\necho hello\n```");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(CodeBlock::class, $children[1]);
    }

    public function testDivInterruptsParagraph(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Note:\n::: warning\nImportant\n:::");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Div::class, $children[1]);
    }

    // ==================== Standard Mode Spec Compliance ====================

    public function testStandardModeBlockquoteDoesNotInterrupt(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testStandardModeOrderedListDoesNotInterrupt(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Steps:\n1. First\n2. Second");

        $children = $doc->getChildren();
        // Should be a single paragraph (ordered lists don't interrupt in djot)
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    // ==================== Nested Blocks in Lists ====================

    public function testNestedListWithoutBlankLine(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
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

    public function testThreeLevelNesting(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- L1\n  - L2\n    - L3");

        $list = $doc->getChildren()[0];
        $l1Item = $list->getChildren()[0];
        $l2List = $l1Item->getChildren()[1];
        $l2Item = $l2List->getChildren()[0];
        $l3List = $l2Item->getChildren()[1];

        $this->assertInstanceOf(ListBlock::class, $l3List);
    }

    public function testBlockquoteInListWithoutBlankLine(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  > quoted");

        $list = $doc->getChildren()[0];
        $item = $list->getChildren()[0];
        $children = $item->getChildren();

        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testMixedListTypes(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Unordered\n  1. Ordered\n  2. Second");

        $list = $doc->getChildren()[0];
        $this->assertSame(ListBlock::TYPE_BULLET, $list->getListType());

        $item = $list->getChildren()[0];
        $sublist = $item->getChildren()[1];
        $this->assertSame(ListBlock::TYPE_ORDERED, $sublist->getListType());
    }

    public function testStandardModeNestedListNeedsBlankLine(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- Item\n  - Not a sublist");

        $list = $doc->getChildren()[0];
        $this->assertCount(1, $list->getChildren());
    }

    // ==================== CarveConverter Integration ====================

    public function testConverterWithBlocksInterruptParagraphs(): void
    {
        $converter = CarveConverter::withBlocksInterruptParagraphs();

        $djot = "Here is a list:\n- item one\n- item two";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<p>Here is a list:</p>', $result);
        $this->assertStringContainsString('<ul>', $result);
    }

    public function testConverterSoftBreaksDefaultToNewline(): void
    {
        $converter = CarveConverter::withBlocksInterruptParagraphs();

        $djot = "Line one\nLine two";
        $result = $converter->convert($djot);

        // Default soft break mode is newline, not <br>
        $this->assertStringNotContainsString('<br>', $result);
    }

    public function testConverterSoftBreaksWithExplicitBreakMode(): void
    {
        $converter = CarveConverter::withBlocksInterruptParagraphs(
            softBreakMode: SoftBreakMode::Break,
        );

        $djot = "Line one\nLine two";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<br>', $result);
    }

    public function testConverterConstructorParameter(): void
    {
        $converter = new CarveConverter(blocksInterruptParagraphs: true);

        $djot = "They said:\n> Important";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Chat Message Use Case ====================

    public function testChatMessageExample(): void
    {
        // For chat applications, combine blocksInterruptParagraphs with SoftBreakMode::Break
        $converter = CarveConverter::withBlocksInterruptParagraphs(
            softBreakMode: SoftBreakMode::Break,
        );

        $djot = <<<'DJOT'
Hey!
Check this out:
- cool feature
- another one
> Someone said this

What do you think?
DJOT;

        $result = $converter->convert($djot);

        // With explicit Break mode, soft breaks render as <br>
        $this->assertStringContainsString('<br>', $result);
        // List should be separate (blocksInterruptParagraphs feature)
        $this->assertStringContainsString('<ul>', $result);
        // Blockquote should be separate (blocksInterruptParagraphs feature)
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Edge Cases ====================

    public function testEscapedListMarkerNotAList(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Text:\n\\- not a list");

        $children = $doc->getChildren();
        // Escaped dash is not a list marker
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHeadingDoesNotInterruptParagraphInDefaultMode(): void
    {
        // In default (spec-compliant) mode, headings do NOT interrupt paragraphs
        $parser = new BlockParser();
        $doc = $parser->parse("Text\n# Heading");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHeadingInterruptsParagraphInBlocksInterruptParagraphsMode(): void
    {
        // In blocksInterruptParagraphs mode, headings CAN interrupt paragraphs
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Text\n# Heading");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Heading::class, $children[1]);
    }

    // ==================== CommonMark Ordered List Rule ====================

    public function testOnlyOneCanInterruptParagraph(): void
    {
        // Only "1." can interrupt a paragraph (CommonMark rule)
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Steps:\n1. First step");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testYearDoesNotBecomeList(): void
    {
        // "1985." should NOT interrupt - prevents years becoming lists
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("My favorite year was\n1985. It was great.");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHighNumberedListDoesNotInterrupt(): void
    {
        // "5." should NOT interrupt paragraphs
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Continue from step\n5. Do this thing");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHighNumberedListAfterBlankLine(): void
    {
        // With blank line, any number can start a list
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Continue from step\n\n5. Do this thing");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testNonTablePipeLineStaysProse(): void
    {
        // A pipe in prose is not a valid table row, so it must not interrupt
        // and split the paragraph into stray blocks.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Das berechnet a\n| b als bitweises Oder.");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }
}
