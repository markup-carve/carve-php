<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class PlainTextListDepthTest extends TestCase
{
    public function testEachListAncestorAddsTwoSpaces(): void
    {
        $source = "- a\n  - b\n    - c\n- d\n";

        $this->assertSame($source, CarveConverter::plainText()->convert($source));
    }
}
