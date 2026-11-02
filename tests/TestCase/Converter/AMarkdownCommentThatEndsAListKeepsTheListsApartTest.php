<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The carve#4917 ruling (CARVE-P2-034): an HTML comment that ends a Markdown
 * list imports as `%%` at its own column, followed by the three-blank list boundary.
 */
class AMarkdownCommentThatEndsAListKeepsTheListsApartTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'example 308' => ["- foo\n- bar\n\n<!-- -->\n\n- baz\n- bim\n", "- foo\n- bar\n\n%%\n\n\n\n- baz\n- bim\n"],
            'no blank before the comment' => ["- a\n<!-- c -->\n- b\n", "- a\n\n%% c\n\n\n\n- b\n"],
            'ordered' => ["1. a\n\n<!-- c -->\n\n1. b\n", "1. a\n\n%% c\n\n\n\n1. b\n"],
            'nested' => ["- x\n  - a\n\n  <!-- c -->\n\n  - b\n- y\n", "- x\n\n  - a\n\n  %% c\n\n\n\n  - b\n\n- y\n"],
            'in a quote' => ["> - a\n>\n> <!-- c -->\n>\n> - b\n", "> - a\n>\n> %% c\n>\n>\n>\n> - b\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testTheCommentKeepsTheListsApart(string $markdown, string $expected): void
    {
        self::assertSame($expected, (new MarkdownToCarve())->convert($markdown));
    }

    public function testACommentAtTheContentColumnStaysInTheItem(): void
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert("- a\n\n  <!-- c -->\n\n- b\n"));

        self::assertSame(1, substr_count($html, '<ul>'));
    }
}
