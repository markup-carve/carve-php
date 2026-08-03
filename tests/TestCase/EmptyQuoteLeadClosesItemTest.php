<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE.
 *
 * `- >` opens an item whose content is an EMPTY block quote. A following
 * column-0 line supplies no container prefix and has no open paragraph anywhere
 * in the stack to fold into, so the item closes and the line is a new top-level
 * block. `- > q` + the same line FOLDS, because there the quote holds an open
 * paragraph - one rule, opposite answers.
 *
 * The grammar names this engine as one of the two that kept the line inside the
 * item (carve#561, carve#572).
 */
class EmptyQuoteLeadClosesItemTest extends TestCase
{
    private function squash(string $html): string
    {
        return trim((string)preg_replace('/>\s+</', '><', $html));
    }

    private function convert(string $source): string
    {
        return $this->squash((new CarveConverter())->convert($source));
    }

    public function testAColumnZeroLineClosesTheItem(): void
    {
        $this->assertSame(
            '<ul><li><blockquote></blockquote></li></ul><p>lazy</p>',
            $this->convert("- >\nlazy\n"),
        );
    }

    public function testABareDotMarkerBehavesTheSameWay(): void
    {
        $this->assertSame(
            '<ol><li><blockquote></blockquote></li></ol><p>X</p>',
            $this->convert(". >\nX\n"),
        );
    }

    public function testAQuoteHoldingAParagraphStillFolds(): void
    {
        $this->assertStringContainsString(
            "<p>q\nlazy</p>",
            $this->convert("- > q\nlazy\n"),
        );
    }

    public function testANestedEmptyQuoteAlsoCloses(): void
    {
        $this->assertStringContainsString('</ul><p>lazy</p>', $this->convert("- > >\nlazy\n"));
    }

    public function testANestedQuoteHoldingAParagraphStillFolds(): void
    {
        $this->assertStringContainsString("q\nlazy", $this->convert("- > > q\nlazy\n"));
    }
}
