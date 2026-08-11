<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParagraphExtent02Test extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function openers(): iterable
    {
        yield 'heading' => ['# Heading'];
        yield 'quote' => ['> quoted'];
        yield 'thematic break' => ['---'];
        yield 'table' => ['| a |'];
        yield 'code fence' => ["```\ncode\n```"];
        yield 'div' => ["::: note\nbody\n:::"];
        yield 'reference definition' => ['[r]: /url'];
        yield 'comment' => ['%% hidden'];
        yield 'block attributes' => ['{.class}'];
    }

    #[DataProvider('openers')]
    public function testBlockOpenersNeedBlockPosition(string $opener): void
    {
        $converter = new CarveConverter();
        self::assertStringStartsWith("<p>intro\n", $converter->convert("intro\n{$opener}"));
        self::assertStringNotContainsString("<p>intro\n", $converter->convert("intro\n\n{$opener}"));
    }

    public function testRuleAppliesInsideQuotesAndItems(): void
    {
        $converter = new CarveConverter();
        self::assertStringContainsString(
            "<p>intro\n# Heading</p>",
            $converter->convert("> intro\n> # Heading"),
        );
        self::assertStringContainsString(
            "<li>intro\n# Heading</li>",
            $converter->convert("- intro\n  # Heading"),
        );
    }

    public function testTightNestedListRemainsStructural(): void
    {
        self::assertStringContainsString(
            "<ul>\n      <li>nested</li>",
            (new CarveConverter())->convert("- intro\n  - nested"),
        );
    }
}
