<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class BareImageBlockPositionTest extends TestCase
{
    public function testAHeadingAfterAnImageStaysInTheOpenParagraph(): void
    {
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n# H</p>\n",
            CarveConverter::create()->convert("![a](/u)\n# H"),
        );
    }

    public function testABlankBeforeTheHeadingKeepsTheImageStandalone(): void
    {
        $this->assertSame(
            "<img src=\"/u\" alt=\"a\">\n<section id=\"H\">\n  <h1>H</h1>\n</section>\n",
            CarveConverter::create()->convert("![a](/u)\n\n# H"),
        );
    }
}
