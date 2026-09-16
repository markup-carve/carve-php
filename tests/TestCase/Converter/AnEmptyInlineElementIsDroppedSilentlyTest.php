<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An inline element the HTML leaves empty is dropped, and reported as nothing:
 * it holds nothing a reader sees, and writing the pair would put its
 * delimiters in the text (ruled on markup-carve/carve-rs#1719).
 */
class AnEmptyInlineElementIsDroppedSilentlyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function emptyElements(): array
    {
        return [
            'a strong' => ['strong'],
            'a bold' => ['b'],
            'an emphasis' => ['em'],
            'an italic' => ['i'],
            'an underline' => ['u'],
            'a strike' => ['s'],
            'a mark' => ['mark'],
            'a superscript' => ['sup'],
            'a subscript' => ['sub'],
        ];
    }

    #[DataProvider('emptyElements')]
    public function testTheElementLeavesNoDelimitersBehind(string $tag): void
    {
        $html = "<p>a<$tag></$tag>b</p>";

        $this->assertSame("ab\n", (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('emptyElements')]
    public function testTheDropIsSilent(string $tag): void
    {
        $report = (new HtmlToCarve())->convertWithReport("<p>a<$tag></$tag>b</p>");

        $this->assertSame([], array_map(static fn ($row): string => $row->code, $report->diagnostics));
    }

    /**
     * The text around it is one run, so nothing reads back as a delimiter.
     */
    #[DataProvider('emptyElements')]
    public function testTheImportReadsBackAsTheHtml(string $tag): void
    {
        $imported = (new HtmlToCarve())->convert("<p>a<$tag></$tag>b</p>");

        $this->assertSame("<p>ab</p>\n", (new CarveConverter())->convert($imported));
    }

    /**
     * BOUND: an element that holds content still writes its pair.
     */
    public function testAnElementWithContentIsUnchanged(): void
    {
        $this->assertSame("a{*x*}b\n", (new HtmlToCarve())->convert('<p>a<strong>x</strong>b</p>'));
    }
}
