<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare span holding its own delimiter between two word characters declines
 * at its closer (carve#4810).
 */
class ABareSpanHoldingItsDelimiterDeclinesTest extends TestCase
{
    /**
     * @return array<array{string}>
     */
    public static function delimiters(): array
    {
        return [['/'], ['_'], ['*'], ['~'], ['=']];
    }

    #[DataProvider('delimiters')]
    public function testTheSpanStaysText(string $delimiter): void
    {
        $source = "{$delimiter}my{$delimiter}var{$delimiter}";
        self::assertSame("<p>$source</p>", self::html($source));
        self::assertStringContainsString("my{$delimiter}var", self::html("{{$source}}"));
    }

    public function testTheRulingsExpected(): void
    {
        self::assertSame(
            "<p>_my_var_ and *a*b* and /usr/local/ and /api/v1/ x</p>\n<p><em>etc</em> and <u>a</u></p>",
            self::html("_my_var_ and *a*b* and /usr/local/ and /api/v1/ x\n\n/etc/ and _a_"),
        );
    }

    /**
     * @return array<array{string, string}>
     */
    public static function openUntilTheCloser(): array
    {
        return [
            ['*x /*y*b*/', '<p>*x <em>*y*b*</em></p>'],
            ['*x*z /*y*/', '<p>*x*z <em>*y*</em></p>'],
            ['/x/z /*y*/', '<p>/x/z /<strong>y</strong>/</p>'],
            ['*x*z *y*', '<p>*x*z *y*</p>'],
            ['*a*b* *c*', '<p>*a*b* <strong>c</strong></p>'],
            ['/a/b *c/ d*', '<p>/a/b <strong>c/ d</strong></p>'],
            ["*x*y /*a*/ z*\n\n*a*", "<p>*x*y <em>*a*</em> z*</p>\n<p><strong>a</strong></p>"],
            ['/*y*b*/', '<p><strong><em>y*b</em></strong></p>'],
        ];
    }

    #[DataProvider('openUntilTheCloser')]
    public function testTheEntryStaysOpenUntilItsCloser(string $source, string $html): void
    {
        self::assertSame($html, self::html($source));
    }

    public function testTheWriterBracesSuchASpan(): void
    {
        self::assertSame("{/my/var/}\n", CarveConverter::toCarve("{/my/var/}\n"));
        self::assertSame("{*a*b*}\n", (new MarkdownToCarve())->convert("**a*b**\n"));
    }

    private static function html(string $source): string
    {
        return trim((new CarveConverter())->convert("$source\n"));
    }
}
