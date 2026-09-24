<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve reads a bare `.`, a letter, a roman numeral or a number past nine
 * digits before `.` or `)` as a list marker. Markdown has none of them, so the
 * import escapes each one that starts a line's text.
 */
class AMarkdownCarveOnlyMarkerIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a bare dot' => [". b\n", "\\. b\n", "<p>. b</p>\n"],
            'a letter' => ["b. c\n", "b\\. c\n", "<p>b. c</p>\n"],
            'an upper-case letter with a paren' => ["B) c\n", "B\\) c\n", "<p>B) c</p>\n"],
            'a roman numeral' => ["iv. c\n", "iv\\. c\n", "<p>iv. c</p>\n"],
            'an initial opening prose' => ["A. Smith wrote it.\n", "A\\. Smith wrote it.\n", "<p>A. Smith wrote it.</p>\n"],
            'ten digits' => ["1234567890. c\n", "1234567890\\. c\n", "<p>1234567890. c</p>\n"],
            'a bare dot before attributes' => [".{#x} b\n", "\\.\\{\\#x} b\n", "<p>.{#x} b</p>\n"],
            'after a heading' => ["# H\nb. c\n", "# H\n\nb\\. c\n", "<section id=\"H\">\n  <h1>H</h1>\n  <p>b. c</p>\n</section>\n"],
            'under an item paragraph' => ["- a\n  . b\n", "- a\n  \\. b\n", "<ul>\n  <li>a\n. b</li>\n</ul>\n"],
            'as an item\'s text' => ["- b. c\n", "- b\\. c\n", "<ul>\n  <li>b. c</li>\n</ul>\n"],
            'after a task box' => ["- [ ] b. c\n", "- [ ] b\\. c\n", "<ul>\n  <li><input type=\"checkbox\" disabled aria-label=\"b. c\"> b. c</li>\n</ul>\n"],
            'in a quote' => ["> . b\n", "> \\. b\n", "<blockquote><p>. b</p></blockquote>\n"],
            'in a quoted item' => ["> - a\n>   i. c\n", "> - a\n>   i\\. c\n", "<blockquote>\n  <ul>\n    <li>a\ni. c</li>\n  </ul>\n</blockquote>\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMarkerStaysText(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function leftAlone(): array
    {
        return [
            'a lone paren' => [") b\n", ") b\n"],
            'no space after the dot' => ["e.g. this\n", "e.g. this\n"],
            'mid-line' => ["text b. c\n", "text b. c\n"],
            'code' => ["```\n. b\n```\n", "```\n. b\n```\n"],
            'a Markdown ordered marker' => ["2. b\n", "2. b\n"],
        ];
    }

    #[DataProvider('leftAlone')]
    public function testTextThatOpensNothingIsLeftAlone(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }
}
