<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownNestedImportLayoutTest extends TestCase
{
    public function testAnIndentedLazyLineUsesItsQuotedItemsContentColumn(): void
    {
        $markdown = "> - foo\n>       # bar\n";
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame("> - foo\n>   \\# bar\n", $imported);
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAdjacentQuotedHeadingsHaveTheirCanonicalSeparator(): void
    {
        $markdown = "> ---\n> Foo\n> ---\n> Bar\n> ---\n> Baz\n";
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame("> ---\n>\n> ## Foo\n>\n> ## Bar\n>\n> Baz\n", $imported);
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testImportKeepsTheAuthoredFinalNewlineState(): void
    {
        $imported = (new MarkdownToCarve())->convert('> - foo');

        self::assertFalse(str_ends_with($imported, "\n"));
    }

    /**
     * Nested list and quote sources.
     *
     * @return array<string, array{string}>
     */
    public static function nestedLayouts(): array
    {
        $body = "foo\n    # bar\n";
        $cases = [];
        foreach (['- x', '1. x', '- [ ] x'] as $marker) {
            foreach (['', '> ', '> > '] as $quote) {
                $source = $quote . $marker . "\n";
                foreach (explode("\n", rtrim($body, "\n")) as $line) {
                    $source .= $quote . '  ' . $line . "\n";
                }
                $cases[$marker . ' in quote depth ' . substr_count($quote, '>')] = [$source];
            }
        }

        return $cases;
    }

    #[DataProvider('nestedLayouts')]
    public function testNestedListAndQuoteVariantsAreFixedPoints(string $markdown): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($imported, CarveConverter::toCarve($imported));
    }
}
