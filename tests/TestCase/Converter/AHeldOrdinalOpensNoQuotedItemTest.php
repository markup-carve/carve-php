<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An ordered marker other than 1 cannot interrupt a quote's paragraph, so its
 * line and the pipe rows under it stay paragraph text: no item and no table
 * (carve-php#4627).
 *
 * Expected HTML follows cmark-gfm 0.29.0.gfm.13: the delimiter row answers the
 * paragraph's last line, marker included, and the cell counts differ.
 */
final class AHeldOrdinalOpensNoQuotedItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function textProvider(): array
    {
        return [
            'the ticket' => [
                "> paragraph\n> 2. | a | b |\n>    |---|---|\n>    | c | d |\n",
                '<blockquote><p>paragraph 2. | a | b | |---|---| | c | d |</p></blockquote>',
            ],
            'a closing parenthesis' => [
                "> p\n> 2) | a | b |\n>    |---|---|\n",
                '<blockquote><p>p 2) | a | b | |---|---|</p></blockquote>',
            ],
            'a nested quote' => [
                "> > p\n> > 2. | a | b |\n> >    |---|---|\n> >    | c | d |\n",
                '<blockquote><blockquote><p>p 2. | a | b | |---|---| | c | d |</p></blockquote></blockquote>',
            ],
            'a quote an item holds' => [
                "- > p\n  > 2. | a | b |\n  >    |---|---|\n  >    | c | d |\n",
                '<ul><li><blockquote><p>p 2. | a | b | |---|---| | c | d |</p></blockquote></li></ul>',
            ],
            'a quote after the item paragraph' => [
                "- a\n\n  > p\n  > 2. | a | b |\n  >    |---|---|\n",
                '<ul><li><p>a</p><blockquote><p>p 2. | a | b | |---|---|</p></blockquote></li></ul>',
            ],
        ];
    }

    #[DataProvider('textProvider')]
    public function testTheLinesStayText(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'a marker starting at 1 interrupts' => [
                "> p\n> 1. | a | b |\n>    |---|---|\n>    | c | d |\n",
                '<blockquote><p>p</p><ol><li><table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr></tbody></table></li></ol></blockquote>',
            ],
            'after a quote blank it opens an item' => [
                "> p\n>\n> 2. | a | b |\n>    |---|---|\n>    | c | d |\n",
                '<blockquote><p>p</p><ol start="2"><li><table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr></tbody></table></li></ol></blockquote>',
            ],
            'a following item of a quoted list' => [
                "- > 1. a\n  > 2. b\n",
                '<ul><li><blockquote><ol><li>a</li><li>b</li></ol></blockquote></li></ul>',
            ],
            'with no pipe the marker is not escaped' => [
                "> p\n> 2) <div>\n",
                '<blockquote><p>p 2) <div></p></blockquote>',
            ],
        ];
    }

    #[DataProvider('unchangedProvider')]
    public function testOtherReadingsStay(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    private function render(string $markdown): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
