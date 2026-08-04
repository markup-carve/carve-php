<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1 loosens an item that holds a blank-line-separated second
 * PARAGRAPH. A comment or a definition renders nothing at all, so there is no
 * second paragraph and the item stays tight - an item wrapped in `<p>` because
 * of a line the reader never sees is the blank line showing through
 * (carve-php#744).
 *
 * L1's OTHER clause is untouched: an item followed by a blank line before the
 * next sibling marker is loose either way, and an invisible line in that gap
 * does not fill it. The corpus pins the pair as 87-compact-list-blocks-4/5
 * against -6.
 */
class InvisibleContentDoesNotLoosenTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testACommentAfterABlankKeepsTheItemTight(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n",
            $this->converter->convert("- a\n\n  %% just a note"),
        );
    }

    public function testADefinitionAfterABlankKeepsTheItemTight(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n",
            $this->converter->convert("- a\n\n  [r]: /u"),
        );
    }

    public function testASiblingAfterTheInvisibleLineStillLoosens(): void
    {
        // The blank before the next marker is the other L1 clause.
        $html = $this->converter->convert("- a\n\n  %% just a note\n- b");

        $this->assertStringContainsString('<li><p>a</p></li>', $html);
        $this->assertStringContainsString('<li><p>b</p></li>', $html);
    }

    public function testARealSecondParagraphStillLoosens(): void
    {
        $html = $this->converter->convert("- a\n\n  b");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
    }

    public function testABlockOpenerAfterABlankStillKeepsItTight(): void
    {
        // Unchanged: Carve's compact-list rule.
        $html = $this->converter->convert("- a\n\n  > q");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('<p>a</p>', $html);
    }

    public function testTheDefinitionStillRegisters(): void
    {
        // Tight does not mean dropped: at the content column it is a real
        // definition and still resolves.
        $html = $this->converter->convert("- a\n\n  [r]: /u\n\nsee [r][]");

        $this->assertStringContainsString('<a href="/u">r</a>', $html);
    }
}
