<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ADelimitedCommentDoesNotPadALineBreakTest extends TestCase
{
    public function testALineBreakNeedsNoDelimiterPadding(): void
    {
        foreach (["a {%\nA b\n %} c\n", "a {% A b %} c\n"] as $source) {
            $this->assertSame($source, CarveConverter::toCarve($source));
        }
    }

    public function testTheClosingPadKeepsItsExistingSpelling(): void
    {
        $this->assertSame("a {% A b\n %} c\n", CarveConverter::toCarve("a {% A b\n%} c\n"));
    }
}
