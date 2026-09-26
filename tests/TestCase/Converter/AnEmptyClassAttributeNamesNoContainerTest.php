<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An empty `class` attribute holds no class token, so it cannot name an
 * admonition: the AST schema requires a non-empty kind.
 */
class AnEmptyClassAttributeNamesNoContainerTest extends TestCase
{
    public function testAnEmptyClassIsDropped(): void
    {
        $this->assertSame("a\n", (new HtmlToCarve())->convert('<div class=""><p>a</p></div>'));
    }

    public function testSurroundingSpaceDoesNotMakeAnEmptyToken(): void
    {
        $this->assertSame("::: x\na\n:::\n", (new HtmlToCarve())->convert('<div class=" x "><p>a</p></div>'));
    }
}
