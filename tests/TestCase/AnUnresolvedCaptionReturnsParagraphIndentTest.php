<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnUnresolvedCaptionReturnsParagraphIndentTest extends TestCase
{
    public function testAllReturnedContinuationLinesUseParagraphIndentation(): void
    {
        $source = "![][r]\n^ p\n p\n  q\n    s\n";
        $this->assertSame(
            "<p>![][r]\n^ p\np\nq\ns</p>",
            trim((new CarveConverter())->convert($source)),
        );
    }
}
