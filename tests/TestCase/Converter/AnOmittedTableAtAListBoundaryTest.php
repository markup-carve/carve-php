<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Markdown table that ends a list ends it in the import too, even when the table writes nothing.
 * No comment ends a Carve list, so the import needs the three blank lines of CARVE-P9-014.
 */
class AnOmittedTableAtAListBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaries(): array
    {
        return [
            'top level' => ["- a\n\n| | |\n| --- | --- |\n\n- b\n", "- a\n\n\n\n- b\n"],
            'ordered' => ["1. a\n\n| |\n| --- |\n\n1. b\n", "1. a\n\n\n\n1. b\n"],
            'in a quote' => ["> - a\n>\n> | | |\n> | --- | --- |\n>\n> - b\n", "> - a\n>\n>\n>\n> - b\n"],
            'in a nested quote' => ["> > - a\n> >\n> > | |\n> > | --- |\n> >\n> > - b\n", "> > - a\n> >\n> >\n> >\n> > - b\n"],
        ];
    }

    #[DataProvider('boundaries')]
    public function testTheListsStaySeparate(string $markdown, string $expected): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($expected, $imported);
        $this->assertSame(2, preg_match_all('/<[ou]l>/', (new CarveConverter())->convert($imported)));
    }

    public function testAnOmittedTableInsideItsItemKeepsOneList(): void
    {
        $imported = (new MarkdownToCarve())->convert("- a\n\n  | |\n  | --- |\n\n- b\n");

        $this->assertSame(1, substr_count((new CarveConverter())->convert($imported), '<ul>'));
    }

    public function testAQuotedTableBelowANestedItemStaysInTheOuterItem(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - a\n>   - x\n>\n>    | h |\n>    | --- |\n>\n> - b\n");

        $this->assertSame("> - a\n>\n>   - x\n>\n>   |= h |\n>\n> - b\n", $imported);
    }

    public function testAnOmittedQuotedTableBelowANestedItemKeepsTheOuterList(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - a\n>   - x\n>\n>    | |\n>    | --- |\n>\n> - b\n");
        $html = (new CarveConverter())->convert($imported);

        $this->assertSame(2, substr_count($html, '<ul>'));
        $this->assertSame(1, substr_count($html, '</ul>' . "\n" . '    </li>'));
    }
}
