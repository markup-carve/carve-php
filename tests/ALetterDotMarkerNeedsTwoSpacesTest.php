<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A letter `.` list marker needs two spaces before its content (carve#4811).
 */
class ALetterDotMarkerNeedsTwoSpacesTest extends TestCase
{
    public function testTheRulingsExpected(): void
    {
        self::assertSame(
            "<p>e. g. foo</p>\n<p>J. R. R. Tolkien wrote it</p>\n<ol type=\"a\">\n  <li>one</li>\n  <li>two</li>\n</ol>",
            self::html("e. g. foo\n\nJ. R. R. Tolkien wrote it\n\na.  one\nb.  two"),
        );
    }

    /**
     * @return array<array{string, bool}>
     */
    public static function markers(): array
    {
        return [
            ['a. x', false], ['iv. x', false], ['I. x', false], ['a.{.k} x', false],
            ['a.  x', true], ['iv.  x', true], ['a.{.k}  x', true], ['a) x', true], ['iv) x', true], ['1. x', true],
        ];
    }

    #[DataProvider('markers')]
    public function testMarker(string $source, bool $list): void
    {
        self::assertSame($list, str_starts_with(self::html($source), '<ol'));
    }

    public function testTheWriterPutsTwoSpacesAfterALetterDot(): void
    {
        self::assertSame("a.  one\nb.  two\n\nI.  x\n\na) p\n\n1. n\n", CarveConverter::toCarve("a.  one\nb.  two\n\nI.  x\n\na) p\n\n1. n\n"));
        self::assertSame("a.{.k}  z\n", CarveConverter::toCarve("a.{.k}  z\n"));
    }

    public function testDjotLetterDotItemsStayLists(): void
    {
        self::assertSame("a.  x\nb.  y\n", (new DjotToCarve())->convert("a. x\nb. y\n"));
        self::assertSame("> i.  x\n> ii.  y\n", (new DjotToCarve())->convert("> i. x\n> ii. y\n"));
        // The widened line keeps its bare `.` content escaped.
        self::assertSame("a.  \\. x\n", (new DjotToCarve())->convert("a. . x\n"));
        // Djot reads `a.{#id} x` as a paragraph, so nothing widens.
        self::assertSame("[a.]{#id} x\n", (new DjotToCarve())->convert("a.{#id} x\n"));
    }

    private static function html(string $source): string
    {
        return trim((new CarveConverter())->convert("$source\n"));
    }
}
