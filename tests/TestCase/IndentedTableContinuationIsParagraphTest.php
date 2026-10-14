<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class IndentedTableContinuationIsParagraphTest extends TestCase
{
    public function testIndentedPlusLineIsVisibleAfterTable(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        $table = $render("| a |\n+ |\n");
        self::assertSame($table . "<p>+ |</p>\n", $render("| a |\n  + |\n"));
    }
}
