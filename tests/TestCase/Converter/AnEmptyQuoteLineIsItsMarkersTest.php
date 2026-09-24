<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `carve fmt` writes an empty line of a block quote as its markers alone, so
 * the import does too. The separator space after the last marker carries no
 * content, and a trailing one made every import with such a line something
 * other than what the formatter writes.
 */
class AnEmptyQuoteLineIsItsMarkersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'between two paragraphs' => ["> a\n>\n> b\n", "> a\n>\n> b\n"],
            'written for a blank source line' => ["> a\n\n> b\n", "> a\n\n> b\n"],
            'in a nested quote' => ["> > a\n> >\n> > b\n", "> > a\n> >\n> > b\n"],
            'above an item the quote holds' => ["> - a\n>\n> - b\n", "> - a\n>\n> - b\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLineIsTheMarkersAlone(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAQuotedCodeLineKeepsTheSpacesItHolds(): void
    {
        $imported = (new MarkdownToCarve())->convert("> ```\n> a\n>  \n> b\n> ```\n");

        $this->assertSame("> ```\n> a\n>  \n> b\n> ```\n", $imported);
        $this->assertSame(
            "<blockquote>\n  <pre><code>a\n \nb\n</code></pre>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }
}
