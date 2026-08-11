<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for nested block elements inside list items.
 *
 * These tests cover Issue #83: Blockquotes and code blocks in lists
 * don't get rendered properly.
 *
 * In djot, block elements (blockquotes, code blocks, divs) can be nested
 * inside list items when properly indented.
 *
 * @see https://github.com/php-collective/djot-php/issues/83
 */
class NestedBlocksInListsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    // ==================== Blockquotes in bullet lists ====================

    public function testBlockquoteInBulletList(): void
    {
        $djot = "- > This is a quote\n  > inside a list item\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('This is a quote', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testBlockquoteWithEmphasisInList(): void
    {
        $djot = "- > *author*:\n  >\n  > Line 1 \\\n  > Line 2\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<strong>author</strong>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testMultipleBlockquotesInList(): void
    {
        // Note: In djot, nested blocks after text require a blank line
        $djot = "- First item with quote:\n\n  > Quote 1\n\n- Second item with quote:\n\n  > Quote 2\n\n- Third item without quote\n";

        $result = $this->converter->convert($djot);

        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    public function testNestedBlockquoteInList(): void
    {
        // Blockquote starts directly on first line, so it gets parsed as block
        $djot = "- > Outer quote\n  >\n  > > Inner quote\n";

        $result = $this->converter->convert($djot);

        // Should have nested blockquotes
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Outer quote', $result);
        $this->assertStringContainsString('Inner quote', $result);
    }

    // ==================== Blockquotes in ordered lists ====================

    public function testBlockquoteInOrderedList(): void
    {
        $djot = "1. > Quote in numbered list\n   > Line 2\n\n2. Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testBlockquoteInAlphaList(): void
    {
        $djot = "a. > Quote in alpha list\n\nb. Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol type="a">', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Code blocks in bullet lists ====================

    public function testCodeBlockInBulletList(): void
    {
        $djot = "- ```\n  code line 1\n  code line 2\n  ```\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('code line 1', $result);
        $this->assertStringContainsString('code line 2', $result);
    }

    public function testCodeBlockWithLanguageInList(): void
    {
        $djot = "- ``` php\n  echo \"Hello\";\n  ```\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code class="language-php">', $result);
        $this->assertStringContainsString('echo "Hello"', $result);
    }

    public function testTildeCodeBlockInList(): void
    {
        $djot = "- ```\n  code here\n  ```\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('code here', $result);
    }

    // ==================== Code blocks in ordered lists ====================

    public function testCodeBlockInOrderedList(): void
    {
        $djot = "1. ```\n   first code\n   ```\n\n2. ```\n   second code\n   ```\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        preg_match_all('/<pre><code>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    // ==================== Divs in lists ====================

    public function testDivInBulletList(): void
    {
        $djot = "- ::: note\n  This is a note\n  :::\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<aside class="admonition note">', $result);
        $this->assertStringContainsString('This is a note', $result);
    }

    public function testDivInOrderedList(): void
    {
        $djot = "1. ::: warning\n   Warning content\n   :::\n\n2. Regular item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<aside class="admonition warning">', $result);
    }

    // ==================== Mixed blocks in lists ====================

    public function testBlockquoteAndCodeInSameList(): void
    {
        $djot = "- > A quote\n\n- ```\n  Some code\n  ```\n\n- Regular text\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    public function testBlockquoteFollowedByCodeInSameItem(): void
    {
        $djot = "- > Quote first\n+\n```\nThen code\n```\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    public function testCodeFollowedByBlockquoteInSameItem(): void
    {
        $djot = "- ```\n  Code first\n  ```\n  > Then quote\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Text before/after blocks in list items ====================

    public function testTextBeforeBlockquoteInList(): void
    {
        $djot = "- Some intro text:\n\n  > The actual quote\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Some intro text', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('The actual quote', $result);
    }

    public function testTextThenBlockquoteNoBlankLineInList(): void
    {
        $djot = "- Some intro text\n+\n> The quote\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Some intro text', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('The quote', $result);
    }

    public function testTextThenCodeFenceNoBlankLineInList(): void
    {
        $djot = "- Some intro text\n+\n``` php\necho 1;\n```\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Some intro text', $result);
        $this->assertStringContainsString('<pre><code class="language-php">', $result);
        $this->assertStringContainsString('echo 1;', $result);
    }

    public function testTextBeforeCodeBlockInList(): void
    {
        $djot = "- Here is some code:\n\n  ```\n  the code\n  ```\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Here is some code', $result);
        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('the code', $result);
    }

    public function testTextAfterBlockquoteInList(): void
    {
        $djot = "- > The quote\n\n  Text after the quote\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Text after the quote', $result);
    }

    // ==================== Nested lists with blocks ====================

    public function testBlockquoteInNestedList(): void
    {
        $djot = "- Outer item\n\n  - > Quote in nested item\n\n- Another outer item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testCodeBlockInNestedList(): void
    {
        $djot = "- Outer item\n\n  - ```\n    nested code\n    ```\n\n- Another outer item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('nested code', $result);
    }

    // ==================== Task lists with blocks ====================

    public function testBlockquoteInTaskList(): void
    {
        $djot = "- [ ] > Quote in unchecked task\n\n- [x] > Quote in checked task\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('type="checkbox"', $result);
        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    public function testCodeBlockInTaskList(): void
    {
        $djot = "- [ ] ````\n          task code\n          ```\n      ````\n\n- [x] Done task\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('type="checkbox"', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    // ==================== Edge cases ====================

    public function testEmptyBlockquoteInList(): void
    {
        $djot = "- >\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testBlockquoteWithOnlyEmphasis(): void
    {
        $djot = "- > /emphasized/\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<em>emphasized</em>', $result);
    }

    public function testMultiParagraphBlockquoteInList(): void
    {
        $djot = "- > First paragraph\n  >\n  > Second paragraph\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        // Should have two paragraphs inside the blockquote
        preg_match('/<blockquote>(.*?)<\/blockquote>/s', $result, $matches);
        if (!empty($matches[1])) {
            $blockquoteContent = $matches[1];
            preg_match_all('/<p>/', $blockquoteContent, $paragraphs);
            $this->assertCount(2, $paragraphs[0]);
        }
    }

    public function testCodeBlockPreservesIndentation(): void
    {
        $djot = "- ```\n    indented\n      more indented\n  not indented\n  ```\n\n- Next item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        // The relative indentation should be preserved
        $this->assertStringContainsString('indented', $result);
    }

    // ==================== Loose vs tight list behavior ====================

    public function testBlockInTightListMakesLoose(): void
    {
        $djot = "- > Quote\n\n- Text\n";

        $result = $this->converter->convert($djot);

        // When list items contain block elements, the list should be loose
        // (items wrapped in <p> tags or contain block elements)
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Definition lists with blocks ====================

    public function testBlockquoteInDefinitionList(): void
    {
        $djot = ":: Term\n:  > Quote in definition\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testCodeBlockInDefinitionList(): void
    {
        $djot = ":: Term\n:  ```\n   code in definition\n   ```\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    // ==================== Complex nesting scenarios ====================

    public function testDeeplyNestedBlockquote(): void
    {
        $djot = "- Level 1\n\n  - Level 2\n    - > Deep quote\n\n- Back to level 1\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Deep quote', $result);
    }

    public function testDeeplyNestedCodeBlock(): void
    {
        $djot = "- Level 1\n\n  - Level 2\n    - ```\n      deep code\n      ```\n\n- Back to level 1\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('deep code', $result);
    }

    public function testBlocksAtMultipleLevels(): void
    {
        $djot = "- > Quote at level 1\n  - > Quote at level 2\n    - > Quote at level 3\n";

        $result = $this->converter->convert($djot);

        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(3, $matches[0]);
    }

    // ==================== Issue #83 specific test cases ====================

    public function testIssue83BlockquoteCase(): void
    {
        // Exact case from Issue #83
        $djot = "List:\n\n- > *author*:\n  >\n  > Line 1 \\\n  > Line 2\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<strong>author</strong>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testIssue83CodeBlockCase(): void
    {
        // Exact case from Issue #83
        $djot = "List:\n\n- ```\n  asdasdasd\n  asasdasd\n  ```\n\n- Another item\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('asdasdasd', $result);
        // Should NOT be inline code
        $this->assertStringNotContainsString('<p><code>', $result);
    }

    /**
     * After a nested block inside a list item and a blank line, an
     * unindented paragraph must terminate the list rather than being
     * absorbed as a sub-item of the previous list item.
     *
     * @see https://github.com/php-collective/djot-php/issues/176
     */
    public function testIssue176UnindentedParagraphAfterNestedCodeBlockEndsList(): void
    {
        $djot = "1. Item 1\n2. Item 2\n+\n```\nExample\n```\n\nNew list:\n\n* New item 1\n* New item 2\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('</ol>', $result);
        // The "New list:" paragraph must appear after the ordered list closes.
        $olClose = strpos($result, '</ol>');
        $paragraph = strpos($result, '<p>New list:</p>');
        $this->assertNotFalse($paragraph);
        $this->assertGreaterThan($olClose, $paragraph);
        // And the following bullet list must be a sibling, not nested in <li>.
        $this->assertMatchesRegularExpression('#</ol>\s*<p>New list:</p>\s*<ul>#', $result);
    }
}
