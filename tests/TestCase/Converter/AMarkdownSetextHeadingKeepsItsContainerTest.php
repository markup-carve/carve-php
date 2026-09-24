<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A setext heading imports as one ATX line in the container that holds it,
 * every line of its paragraph folded in, as cmark-gfm reads it and carve-js
 * writes it. Written at column 0, a heading after a blank in an item left the
 * list (the setext spelling of converter corpus case 66).
 */
class AMarkdownSetextHeadingKeepsItsContainerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'an h2 after a blank in an item' => ["- a\n\n  b\n  ---\n", "{loose}\n- a\n\n  ## b\n"],
            'an h1 after a blank in an item' => ["- a\n\n  b\n  ===\n", "{loose}\n- a\n\n  # b\n"],
            'a two-line heading after a blank in an item' => ["- a\n\n  b\n  c\n  ---\n", "{loose}\n- a\n\n  ## b c\n"],
            'a two-line heading at the top level' => ["b\nc\n---\n", "## b c\n"],
            'a two-line heading in a quote' => ["> b\n> c\n> ===\n", "> # b c\n"],
            'a heading on a quoted item line' => ["> - b\n>   ---\n", "> - ## b\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheHeadingStaysInItsContainer(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    #[DataProvider('shapes')]
    public function testTheImportIsAWriterFixedPoint(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * After a blank quote line inside a quoted item. The writer spells the
     * empty quote line `>`, where this importer keeps `> `, so this case pins
     * the render and the structure rather than the bytes.
     */
    public function testAHeadingAfterABlankInAQuotedItemStaysInTheItem(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - a\n>\n>   b\n>   ---\n");

        $this->assertSame("> {loose}\n> - a\n> \n>   ## b\n", $imported);
        $this->assertSame(
            (new CarveConverter())->convert("> {loose}\n> - a\n>\n>   ## b\n"),
            (new CarveConverter())->convert($imported),
        );
        $this->assertStringContainsString('<li><p>a</p>', (new CarveConverter())->convert($imported));
    }
}
