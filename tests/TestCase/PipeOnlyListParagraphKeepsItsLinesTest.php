<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class PipeOnlyListParagraphKeepsItsLinesTest extends TestCase
{
    use RuleATodo;

    public function testPipeLinesStayInTheOpenParagraph(): void
    {
        $html = (new HtmlRenderer())->render((new BlockParser())->parse("- b\n|\n ||\n"));
        self::assertSame("<ul>\n  <li>b\n|\n||</li>\n</ul>\n", $html);
    }
}
