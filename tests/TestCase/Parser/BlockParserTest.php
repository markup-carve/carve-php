<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Parser;

use Carve\Node\Block\BlockQuote;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\DefinitionList;
use Carve\Node\Block\Div;
use Carve\Node\Block\Heading;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\Paragraph;
use Carve\Node\Block\Table;
use Carve\Node\Block\ThematicBreak;
use Carve\Node\Document;
use Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;
use function str_contains;

class BlockParserTest extends TestCase
{
    protected BlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
    }

    public function testParseParagraph(): void
    {
        $doc = $this->parser->parse('Hello world');

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
    }

    public function testParseMultipleParagraphs(): void
    {
        $doc = $this->parser->parse("First paragraph\n\nSecond paragraph");

        $this->assertCount(2, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[1]);
    }

    public function testParseHeading(): void
    {
        $doc = $this->parser->parse('# Heading 1');

        $this->assertCount(1, $doc->getChildren());
        $heading = $doc->getChildren()[0];
        $this->assertInstanceOf(Heading::class, $heading);
        $this->assertSame(1, $heading->getLevel());
    }

    public function testParseHeadingPrecedingAttributesQuoteAware(): void
    {
        // Heading attributes come from a PRECEDING block-attribute line
        // (djot-strict, spec PART 2 headings / PART 9 §15). The attribute
        // line tolerates a `}` inside a quoted value, in either quote form,
        // and a `\"` escape does not end the value early.
        $cases = [
            "{k=\"{y}\"}\n# H" => '{y}',
            "{k='{y}'}\n# H" => '{y}',
            "{k=\"a\\\"b\"}\n# H" => 'a"b',
        ];
        foreach ($cases as $src => $expected) {
            $heading = $this->parser->parse($src)->getChildren()[0];
            $this->assertInstanceOf(Heading::class, $heading);
            $this->assertSame($expected, $heading->getAttribute('k'), 'for ' . $src);
        }
    }

    public function testParseHeadingTrailingBraceBlockIsLiteralText(): void
    {
        // djot-strict: a heading line carries NO trailing attribute block —
        // a trailing `{…}` is ordinary inline content (matches carve-js #153
        // and corpus 02-headings).
        $heading = $this->parser->parse('## Setup {#install .featured}')->getChildren()[0];
        $this->assertInstanceOf(Heading::class, $heading);
        $this->assertNull($heading->getAttribute('id'));
        $this->assertNull($heading->getAttribute('class'));
    }

    public function testParseHeadingLevels(): void
    {
        $doc = $this->parser->parse("# H1\n\n## H2\n\n### H3\n\n#### H4\n\n##### H5\n\n###### H6");

        $children = $doc->getChildren();
        $this->assertCount(6, $children);

        for ($i = 0; $i < 6; $i++) {
            $this->assertInstanceOf(Heading::class, $children[$i]);
            $this->assertSame($i + 1, $children[$i]->getLevel());
        }
    }

    public function testParseCodeBlock(): void
    {
        $doc = $this->parser->parse("```php\necho 'hello';\n```");

        $this->assertCount(1, $doc->getChildren());
        $codeBlock = $doc->getChildren()[0];
        $this->assertInstanceOf(CodeBlock::class, $codeBlock);
        $this->assertSame('php', $codeBlock->getLanguage());
        $this->assertSame("echo 'hello';", $codeBlock->getContent());
    }

    public function testParseCodeBlockWithTildes(): void
    {
        $doc = $this->parser->parse("~~~\ncode\n~~~");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(CodeBlock::class, $doc->getChildren()[0]);
    }

    public function testParseBlockQuote(): void
    {
        $doc = $this->parser->parse('> Quoted text');

        $this->assertCount(1, $doc->getChildren());
        $blockquote = $doc->getChildren()[0];
        $this->assertInstanceOf(BlockQuote::class, $blockquote);
    }

    public function testParseNestedBlockQuote(): void
    {
        $doc = $this->parser->parse("> Level 1\n>\n> > Level 2");

        $this->assertCount(1, $doc->getChildren());
        $outer = $doc->getChildren()[0];
        $this->assertInstanceOf(BlockQuote::class, $outer);

        // Should have nested blockquote
        $hasNestedQuote = false;
        foreach ($outer->getChildren() as $child) {
            if ($child instanceof BlockQuote) {
                $hasNestedQuote = true;

                break;
            }
        }
        $this->assertTrue($hasNestedQuote);
    }

    public function testParseUnorderedList(): void
    {
        $doc = $this->parser->parse("- Item 1\n- Item 2\n- Item 3");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
        $this->assertCount(3, $list->getChildren());
    }

    public function testParseOrderedList(): void
    {
        $doc = $this->parser->parse("1. First\n2. Second\n3. Third");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
    }

    public function testParseTaskList(): void
    {
        $doc = $this->parser->parse("- [ ] Unchecked\n- [x] Checked");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
    }

    public function testParseDefinitionList(): void
    {
        $doc = $this->parser->parse(":: Term\n:  Definition");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(DefinitionList::class, $doc->getChildren()[0]);
    }

    public function testParseDefinitionListContinuationLineKeepsSameDd(): void
    {
        $djot = ":: Term\n:  First line\n   Second line";
        $doc = $this->parser->parse($djot);

        $dl = $doc->getChildren()[0];
        $this->assertInstanceOf(DefinitionList::class, $dl);

        // Should have: dt + dd = 2 children (one dd with multiple paragraphs)
        $children = $dl->getChildren();
        $dds = array_filter($children, fn ($c) => $c->getType() === 'definition_description');
        $this->assertCount(1, $dds);
    }

    public function testParseDefinitionListEmptyDd(): void
    {
        $djot = ':: Term';
        $doc = $this->parser->parse($djot);

        $dl = $doc->getChildren()[0];
        $this->assertInstanceOf(DefinitionList::class, $dl);

        $children = $dl->getChildren();
        $dds = array_filter($children, fn ($c) => $c->getType() === 'definition_description');
        $this->assertCount(0, $dds);
    }

    public function testParseThematicBreak(): void
    {
        $doc = $this->parser->parse('---');

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(ThematicBreak::class, $doc->getChildren()[0]);
    }

    public function testParseThematicBreakVariants(): void
    {
        // Note: Spaced variants like "- - -" may be parsed as lists
        $variants = ['---', '***'];

        foreach ($variants as $variant) {
            $doc = $this->parser->parse($variant);
            $this->assertInstanceOf(
                ThematicBreak::class,
                $doc->getChildren()[0],
                "Failed for variant: $variant",
            );
        }
    }

    public function testParseFencedDiv(): void
    {
        $doc = $this->parser->parse(":::\nContent\n:::");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Div::class, $doc->getChildren()[0]);
    }

    public function testParseFencedDivWithClass(): void
    {
        $doc = $this->parser->parse("::: warning\nContent\n:::");

        $div = $doc->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $class = $div->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'warning'));
    }

    public function testParseFencedDivMergesAttributeBlockWithFenceClass(): void
    {
        // When an attribute block specifies classes before a fenced div,
        // both the fence class and attribute classes should be merged
        $doc = $this->parser->parse("{.custom-style #my-div}\n::: info\nContent\n:::");

        $div = $doc->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);

        // Should have both the fence class and the attribute class
        $class = $div->getAttribute('class') ?? '';
        $this->assertStringContainsString('info', $class);
        $this->assertStringContainsString('custom-style', $class);

        // ID should also be preserved
        $this->assertSame('my-div', $div->getAttribute('id'));
    }

    public function testParseFencedDivMergesMultipleClasses(): void
    {
        $doc = $this->parser->parse("{.extra .another}\n::: note\nContent\n:::");

        $div = $doc->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);

        $class = $div->getAttribute('class') ?? '';
        $this->assertStringContainsString('note', $class);
        $this->assertStringContainsString('extra', $class);
        $this->assertStringContainsString('another', $class);
    }

    public function testNestedFencedDivsWithAttributeBlocks(): void
    {
        // When nested divs have attribute blocks, each div should merge
        // its own attribute classes with its fence class
        $djot = <<<'DJOT'
:::: info

Outer

{.custom-nested}
::: warning
Nested
:::

::::
DJOT;

        $doc = $this->parser->parse($djot);

        // Outer div should have only 'info' class
        $outerDiv = $doc->getChildren()[0];
        $this->assertInstanceOf(Div::class, $outerDiv);
        $this->assertSame('info', $outerDiv->getAttribute('class'));

        // Find nested div
        $children = $outerDiv->getChildren();
        $nestedDiv = null;
        foreach ($children as $child) {
            if ($child instanceof Div) {
                $nestedDiv = $child;

                break;
            }
        }

        $this->assertNotNull($nestedDiv, 'Nested div should exist');
        $nestedClass = $nestedDiv->getAttribute('class') ?? '';

        // Nested div should have both 'warning' and 'custom-nested' merged
        $this->assertStringContainsString('warning', $nestedClass);
        $this->assertStringContainsString('custom-nested', $nestedClass);
    }

    public function testParseTable(): void
    {
        $doc = $this->parser->parse("| A | B |\n|---|---|\n| 1 | 2 |");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Table::class, $doc->getChildren()[0]);
    }

    public function testParseBlockAttributes(): void
    {
        $doc = $this->parser->parse("{.highlight}\n# Heading");

        $heading = $doc->getChildren()[0];
        $this->assertInstanceOf(Heading::class, $heading);
        $class = $heading->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'highlight'));
    }

    public function testParseBlockAttributeWithId(): void
    {
        $doc = $this->parser->parse("{#intro}\nParagraph text");

        $para = $doc->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $para);
        $this->assertSame('intro', $para->getAttribute('id'));
    }

    public function testParseBooleanAttribute(): void
    {
        // {reversed} should create a boolean attribute with empty value
        $doc = $this->parser->parse("{reversed}\n1. First\n2. Second");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('', $list->getAttribute('reversed'));
    }

    public function testParseBooleanAttributeWithOthers(): void
    {
        // Boolean attr combined with class, id
        $doc = $this->parser->parse("{#mylist .fancy reversed}\n1. First\n2. Second");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('mylist', $list->getAttribute('id'));
        $this->assertSame('fancy', $list->getAttribute('class'));
        $this->assertSame('', $list->getAttribute('reversed'));
    }

    public function testParseMultipleBooleanAttributes(): void
    {
        // Multiple boolean attrs
        $doc = $this->parser->parse("{hidden inert}\nParagraph");

        $para = $doc->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $para);
        $this->assertSame('', $para->getAttribute('hidden'));
        $this->assertSame('', $para->getAttribute('inert'));
    }

    public function testParseReferenceDefinition(): void
    {
        $doc = $this->parser->parse("[example]: https://example.com\n\n[example][]");

        $ref = $this->parser->getReference('example');
        $this->assertNotNull($ref);
        $this->assertSame('https://example.com', $ref->url);
    }

    public function testParseFootnoteDefinition(): void
    {
        $doc = $this->parser->parse("[^note]: Footnote content\n\nText[^note]");

        $this->assertTrue($this->parser->hasFootnote('note'));
    }

    public function testEmptyInput(): void
    {
        $doc = $this->parser->parse('');

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(0, $doc->getChildren());
    }

    public function testWhitespaceOnlyInput(): void
    {
        $doc = $this->parser->parse("   \n\n   \n");

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(0, $doc->getChildren());
    }

    public function testParagraphInterruptionNestedLists(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- Fruits\n  - Apples\n  - Bananas\n- Vegetables");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertCount(2, $list->getChildren()); // Fruits, Vegetables

        // Check first item has a sublist
        $firstItem = $list->getChildren()[0];
        $children = $firstItem->getChildren();

        // Should have paragraph and sublist
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);

        // Sublist should have 2 items
        $sublist = $children[1];
        $this->assertCount(2, $sublist->getChildren());
    }

    public function testParagraphInterruptionThreeLevels(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- L1\n  - L2\n    - L3");

        $list = $doc->getChildren()[0];
        $l1Item = $list->getChildren()[0];
        $l2List = $l1Item->getChildren()[1];
        $l2Item = $l2List->getChildren()[0];
        $l3List = $l2Item->getChildren()[1];

        $this->assertInstanceOf(ListBlock::class, $l3List);
        $this->assertCount(1, $l3List->getChildren());
    }

    public function testParagraphInterruptionMixedListTypes(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- Unordered\n  1. Ordered\n  2. Second");

        $list = $doc->getChildren()[0];
        $this->assertSame(ListBlock::TYPE_BULLET, $list->getListType());

        $item = $list->getChildren()[0];
        $sublist = $item->getChildren()[1];
        $this->assertInstanceOf(ListBlock::class, $sublist);
        $this->assertSame(ListBlock::TYPE_ORDERED, $sublist->getListType());
    }

    public function testParagraphInterruptionBlockquoteFoldsInList(): void
    {
        // Djot variant: a block quote after a prose line (no blank line) does not
        // interrupt the item's open paragraph. The `>` folds into the item text;
        // a blank line would be required to start a nested block quote.
        $parser = new BlockParser();
        $doc = $parser->parse("- Item\n  > quoted");

        $list = $doc->getChildren()[0];
        $item = $list->getChildren()[0];
        $children = $item->getChildren();

        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testParagraphInterruptionBulletFoldsWithoutBlankLine(): void
    {
        // Symmetric interruption: a bullet without a preceding blank line folds
        // into the paragraph (like an ordered marker). A blank line is required
        // to start the list.
        $parser = new BlockParser();
        $doc = $parser->parse("Here is a list:\n- item one\n- item two");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);

        $withBlank = $parser->parse("Here is a list:\n\n- item one\n- item two");
        $blankChildren = $withBlank->getChildren();
        $this->assertCount(2, $blankChildren);
        $this->assertInstanceOf(Paragraph::class, $blankChildren[0]);
        $this->assertInstanceOf(ListBlock::class, $blankChildren[1]);
    }

    public function testParagraphInterruptionBlockquoteFoldsIntoParagraph(): void
    {
        // Djot variant: a block quote does not interrupt an open paragraph. The
        // `>` marker folds into the paragraph as literal text; a blank line is
        // required to start the block quote.
        $parser = new BlockParser();
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testCodeBlockTrimsLeadingAndTrailingBlankLines(): void
    {
        // Leading and trailing blank lines inside code block should be trimmed
        $doc = $this->parser->parse("```\n\nbin/cake linter\n\n```");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(CodeBlock::class, $children[0]);
        $this->assertSame('bin/cake linter', $children[0]->getContent());
    }

    public function testCodeBlockPreservesInternalBlankLines(): void
    {
        // Blank lines in the middle of content should be preserved
        $doc = $this->parser->parse("```\nline1\n\nline2\n```");

        $children = $doc->getChildren();
        $this->assertInstanceOf(CodeBlock::class, $children[0]);
        $this->assertSame("line1\n\nline2", $children[0]->getContent());
    }

    public function testRawBlockTrimsLeadingAndTrailingBlankLines(): void
    {
        // Leading and trailing blank lines inside raw block should be trimmed
        $doc = $this->parser->parse("``` =html\n\n<b>bold</b>\n\n```");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame('<b>bold</b>', $children[0]->getContent());
    }
}
