<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListMarkerDefinitionAfterParagraphTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lazyDefinitions(): iterable
    {
        yield 'link at document level' => ["para\n- [d]: u\n\n[go][d]\n", "para\n- [d]: u"];
        yield 'link in quote' => ["> r\n> - [d]: u\n\n[go][d]\n", "r\n- [d]: u"];
        yield 'link in div' => ["::: n\nr\n- [d]: u\n:::\n\n[go][d]\n", "r\n- [d]: u"];
        yield 'footnote at document level' => ["para\n- [^f]: t\n\nsee[^f]\n", "para\n- [^f]: t"];
        yield 'footnote in quote' => ["> r\n> - [^f]: t\n\nsee[^f]\n", "r\n- [^f]: t"];
    }

    #[DataProvider('lazyDefinitions')]
    public function testDefinitionOnLazyMarkerLineIsOnlyParagraphText(string $source, string $text): void
    {
        $html = $this->converter->convert($source);

        self::assertStringContainsString($text, $html);
        self::assertStringNotContainsString('href="u"', $html);
        self::assertStringNotContainsString('doc-noteref', $html);
        self::assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testHeadingLeavesNoParagraphForFootnoteMarkerToContinue(): void
    {
        $html = $this->converter->convert("# h\n- [^f]: t\n\n[^f] ref\n");

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('role="doc-noteref"', $html);
        self::assertStringContainsString('role="doc-endnotes"', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function columnZeroControls(): iterable
    {
        yield 'indented item prose' => ["- a\n  more\n* [d]: u\n\n[go][d]\n"];
        yield 'lazy item prose' => ["- a\nlazy\n* [d]: u\n\n[go][d]\n"];
        yield 'quote under top-level prose' => ["para\n> - [d]: u\n\n[go][d]\n"];
    }

    #[DataProvider('columnZeroControls')]
    public function testColumnZeroMarkerStillCollects(string $source): void
    {
        self::assertStringContainsString('<a href="u">go</a>', $this->converter->convert($source));
    }

    public function testAbbreviationMarkerStillStaysParagraphText(): void
    {
        $html = $this->converter->convert("para\n* [A]: alpha\n\nA\n");

        self::assertStringContainsString("para\n* [A]: alpha", $html);
        self::assertStringNotContainsString('<abbr', $html);
    }
}
