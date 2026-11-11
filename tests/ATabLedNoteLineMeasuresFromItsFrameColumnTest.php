<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5987: a tab after a frame's content column stops relative to that column (PART 9 §24 C5),
 * and the writer keeps a note's paragraph from taking the paragraph below it (PART 11 §1).
 */
final class ATabLedNoteLineMeasuresFromItsFrameColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'row 1075: an attribute line in a note a nested plus passes' => [
                "1. x\n   1. +\n   [^m]: n\n   \t{#i}\n   q\n\np[^m]\n",
                "<ol>\n  <li>x\n    <ol>\n      <li>q</li>\n    </ol>\n  </li>\n</ol>\n<p>p<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            'a note continuation after a three-column item' => [
                "1. x\n   [^m]: n\n   \tmore\n\np[^m]\n",
                "<ol>\n  <li>x</li>\n</ol>\n<p>p<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\nmore<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            'two attribute lines after a four-column item' => [
                "10. x\n    1. +\n    [^m]: n\n    \t{#i}\n    \t{.d}\n    q\n\np[^m]\n",
                "<ol start=\"10\">\n  <li>x\n    <ol>\n      <li>q</li>\n    </ol>\n  </li>\n</ol>\n<p>p<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            'a description host' => [
                "t\n: x\n  1. +\n  [^m]: n\n  \t{.c}\n  q\n\np[^m]\n",
                "<p>t\n: x\n1. +</p>\n<p>q</p>\n<p>p<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineStaysInTheNote(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formatted(): array
    {
        $sources = array_map(static fn (array $case): array => [$case[0]], self::cases());
        $sources['an attribute line ends a note between two item paragraphs'] = ["- x\n  [^m]: n\n  \t{#i}\n  q\n\np[^m]\n"];
        $sources['a space-led attribute line'] = ["- x\n  [^m]: n\n    {#i}\n  q\n\np[^m]\n"];

        return $sources;
    }

    #[DataProvider('formatted')]
    public function testFmtKeepsTheReading(string $source): void
    {
        $written = CarveConverter::toCarve($source);

        self::assertSame($written, CarveConverter::toCarve($written));
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written));
    }

    public function testFmtEndsTheNoteParagraphWithAnAttributeLine(): void
    {
        self::assertSame(
            "- x\n  [^m]: n\n    {empty}\n  q\n\np[^m]\n",
            CarveConverter::toCarve("- x\n  [^m]: n\n    {#i}\n  q\n\np[^m]\n"),
        );
    }
}
