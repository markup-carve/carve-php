<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class NestedNoteReleasesFlushLineTest extends TestCase
{
    public function testInvisibleNestedNoteLeavesNoLazyParagraph(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertStringEndsWith("</ul>\n<p>.</p>\n", $render("- - A\n    [^n]: y\n.\n"));
        self::assertStringContainsString("A\nplain\n.</li>", $render("- - A\n    plain\n.\n"));
    }
}
