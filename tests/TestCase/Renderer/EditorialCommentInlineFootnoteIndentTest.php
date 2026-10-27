<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EditorialCommentInlineFootnoteIndentTest extends TestCase
{
    public function testInlineFootnoteIndentDoesNotEnterComment(): void
    {
        $html = (new CarveConverter())->convert("^[{#\n#}]\n");

        self::assertStringContainsString("<span class=\"critic-comment\">\n</span>", $html);
    }

    public function testTopLevelCommentKeepsTheSameContent(): void
    {
        $html = (new CarveConverter())->convert("{#\n#}\n");

        self::assertStringContainsString("<span class=\"critic-comment\">\n</span>", $html);
    }
}
