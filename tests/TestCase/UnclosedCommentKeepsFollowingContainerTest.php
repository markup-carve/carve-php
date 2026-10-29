<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class UnclosedCommentKeepsFollowingContainerTest extends TestCase
{
    use RuleATodo;

    public function testThreePercentLineDoesNotHideNestedContainer(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame($render("- tail\n  %%\n   :::: \n"), $render("- tail\n  %%%\n   :::: \n"));
        self::assertStringContainsString('<div>', $render("- tail\n  %%%\n   ::::\n"));
        $nested = "> 1. 1. - b\n>    \t {.c}\n>    %%%\n>     \t ::: note\n";
        self::assertStringContainsString('<p class="c">::: note</p>', $render($nested));
    }
}
