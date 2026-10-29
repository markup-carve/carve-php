<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class NoteCommentRetainsIndentedFollowerTest extends TestCase
{
    use RuleATodo;

    public function testIndentedLineAfterNoteCommentStaysInvisible(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame("<section id=\"H\">\n  <h1>H</h1>\n</section>\n", $render("[^n]: note\n%%\n > x\n# H\n"));
        self::assertStringContainsString('<p>&gt; x</p>', $render("%%\n > x\n# H\n"));
    }
}
