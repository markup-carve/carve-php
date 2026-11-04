<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class ErasedBoundaryCommentsTest extends TestCase
{
    public function testAQuotedOmittedTableStillEndsItsItemBeforeAFlushLine(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - a\n>   | | |\n>   | --- | --- |\n> x\n> - b\n");
        $html = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('<p>x</p>', $html);
        $this->assertSame(2, substr_count($html, '<ul>'));
    }

    public function testAQuotedDjotCommentBetweenBlanksKeepsOneList(): void
    {
        $imported = (new DjotToCarve())->convert("> - a\n>\n>   {% c %}\n>\n> - b\n");

        $this->assertSame("> - a\n>\n> - b\n", $imported);
    }

    public function testATopLevelCommentLineIsKept(): void
    {
        $this->assertSame("%%\nhi\n", (new DjotToCarve())->convert("{% c %}\nhi\n"));
    }
}
