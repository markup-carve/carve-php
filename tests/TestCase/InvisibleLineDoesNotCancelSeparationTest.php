<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1b: an invisible line is not the second paragraph, and it is not
 * a separator either - it cannot stand between the blank line and the paragraph
 * that follows.
 *
 * Testing only the FIRST line after the blank stopped at the comment and left
 * the item tight, so deleting the comment changed how BOTH paragraphs render -
 * a line that outputs nothing making a visible difference (carve-php#771).
 */
class InvisibleLineDoesNotCancelSeparationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAParagraphBehindACommentStillLoosens(): void
    {
        $html = $this->converter->convert("- a\n\n  %% n\n  text");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>text</p>', $html);
    }

    public function testTheSameDocumentWithoutTheCommentIsAlreadyLoose(): void
    {
        // The control the rule rests on: deleting an invisible line may not
        // change how the blocks around it render.
        $html = $this->converter->convert("- a\n\n  text");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>text</p>', $html);
    }

    public function testAnInvisibleLineOnItsOwnStillLeavesTheItemTight(): void
    {
        // carve#621's rule, unchanged: the invisible line is not the second
        // paragraph, so with nothing behind it the item stays tight.
        $html = $this->converter->convert("- a\n\n  %% n");

        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringContainsString('<li>a</li>', $html);
    }

    public function testADefinitionBehindTheBlankAlsoLoosensWhatFollowsIt(): void
    {
        $html = $this->converter->convert("- a\n\n  [r]: /u\n  text");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>text</p>', $html);
    }
}
