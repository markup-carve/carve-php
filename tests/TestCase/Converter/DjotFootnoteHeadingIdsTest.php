<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotFootnoteHeadingIdsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'referenced note before heading' => ["# x\n\nref[^n]\n\n[^n]: note {#x-1} too\n\n# x\n", ['x', 'x-2']];
        yield 'unreferenced note before heading' => ["# x\n\n[^n]: note {#x-1} too\n\n# x\n", ['x', 'x-2']];
        yield 'attached id inside note' => ["# x\n\n[^n]: [note]{#x-1} too\n\n# x\n", ['x', 'x-2']];
        yield 'note after both headings' => ["# x\n\n# x\n\n[^n]: note {#x-1} too\n", ['x', 'x-1']];
        yield 'note before first heading' => ["[^n]: note {#x} too\n\n# x\n", ['x-1']];
        yield 'continued heading before note' => ["# x\n# y\n\n[^n]: n {#x-y-1} t\n\n# x y\n", ['x-y', 'x-y-2']];
        yield 'note inside blockquote' => ["# x\n\n> # x\n>\n> [^n]: note {#x-1} too\n\n# x\n", ['x', 'x-2']];
        yield 'block attribute inside note' => ["# x\n\n[^n]: note\n\n    {#x-1}\n    more\n\n# x\n", ['x', 'x-2']];
        yield 'heading on note definition line' => ["# a\n\nr[^n]\n\n[^n]: # q\n\n# a\n\n[^m]: m {#a-1} t\n\n# a\n", ['a', 'a-1', 'a-2']];
        yield 'indented heading inside note' => ["# a\n\nr[^n]\n\n[^n]: note {#a-1} x\n\n    # a\n\n# a\n", ['a', 'a-3']];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testFootnoteIdsReserveInSourceOrder(string $source, array $expected): void
    {
        $carve = (new DjotToCarve())->convert($source);
        $html = (new CarveConverter())->convert($carve);
        preg_match_all('/<section id="([^"]+)/', $html, $matches);

        self::assertSame($expected, $matches[1]);
        self::assertStringNotContainsString('note {#', $carve);
    }

    public function testFootnoteHeadingInsideBlockquoteKeepsSourceIds(): void
    {
        $source = "# a\n\n> r[^n]\n>\n> [^n]: # a\n\n[^m]: m {#a-1} t\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<section id="a-2">', $html);
        self::assertStringContainsString('<h1 id="a-1">a</h1>', $html);
    }

    public function testIndentedFootnoteHeadingInsideBlockquoteKeepsSourceIds(): void
    {
        $source = "# a\n\n> r[^n]\n>\n> [^n]: n {#a-1}\n>\n>     # a\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<section id="a-3">', $html);
        self::assertStringContainsString('<h1 id="a-2">a</h1>', $html);
    }

    public function testListFootnoteHeadingWithoutAttributesKeepsFirstId(): void
    {
        $source = "# a\n\n- r[^n]\n\n  [^n]: # a\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<section id="a-2">', $html);
        self::assertStringContainsString('<h1 id="a-1">a</h1>', $html);
    }

    public function testListFootnoteHeadingWithReservationKeepsFirstId(): void
    {
        $source = "# a\n\n- r[^n]\n\n  [^n]: n {#a-1}\n\n      # a\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<section id="a-3">', $html);
        self::assertStringContainsString('<h1 id="a-2">a</h1>', $html);
    }

    public function testHeadingAfterNestedListFootnoteStaysOutsideNote(): void
    {
        foreach (
            [
                "# a\n\n- r[^n]\n\n  [^n]: # a\n\n  # a\n\n# a\n",
                "# a\n\n> - r[^n]\n>\n>   [^n]: # a\n>\n>   # a\n\n# a\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
            preg_match_all('/(?:section|h1) id="([^"]+)/', $html, $ids);

            self::assertSame(['a', 'a-2', 'a-3', 'a-1'], $ids[1]);
        }
    }

    public function testFootnoteHeadingOneSpacePastDefinitionStaysInsideNote(): void
    {
        foreach (
            [
                "# a\n\nr[^n]\n\n[^n]: # a\n\n # a\n\n# a\n",
                "# a\n\n- r[^n]\n\n  [^n]: # a\n\n   # a\n\n# a\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
            preg_match_all('/(?:section|h1) id="([^"]+)/', $html, $ids);

            self::assertSame(['a', 'a-3', 'a-1', 'a-2'], $ids[1]);
        }
    }

    public function testParagraphHashLineDoesNotShiftReservations(): void
    {
        $source = "# a\n\npara\n# a\n\nr[^n]\n\n[^n]: n {#a-1}\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<section id="a-2">', $html);
    }

    public function testNestedListHeadingCountsBeforeFootnoteReservation(): void
    {
        $source = "# a\n\n- x\n\n  - y\n\n    # a\n\nr[^n]\n\n[^n]: n {#a-1}\n\n# a\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<h1 id="a-1">a</h1>', $html);
        self::assertStringContainsString('<section id="a-2">', $html);
    }

    public function testReferenceToLaterHeadingKeepsItsTarget(): void
    {
        $source = "# x\n\nref[^n]\n\n[^n]: note {#x-1} too\n\n# x\n\n[go](#x-2)\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="x-2">', $html);
        self::assertStringContainsString('href="#x-2"', $html);
    }
}
