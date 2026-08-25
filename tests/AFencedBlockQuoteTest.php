<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1718. A colon fence whose type token is a bare `>` is a
 * second SPELLING of the block quote: the tree it produces is the one the
 * `>`-prefixed form produces, so every assertion here compares the two
 * spellings rather than pinning HTML.
 */
class AFencedBlockQuoteTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testRendersTheElementThePrefixedFormRenders(): void
    {
        $this->assertSame($this->html("> hello\n"), $this->html("::: >\nhello\n:::\n"));
    }

    public function testNestsInItselfAtConstantFenceWidthLeavingNothingBehind(): void
    {
        $nested = "::: >\nouter\n\n::: >\ninner\n:::\n:::\n";
        $this->assertSame($this->html("> outer\n>\n> > inner\n"), $this->html($nested));
    }

    public function testKeepsTheSpellingItWasWrittenIn(): void
    {
        $this->assertSame("::: >\nhello\n:::\n", CarveConverter::toCarve("::: >\nhello\n:::\n"));
        $this->assertSame("> hello\n", CarveConverter::toCarve("> hello\n"));
    }
}
