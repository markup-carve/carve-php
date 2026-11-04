<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Djot list whose later item leads with nothing visible is marked `{loose}`
 * above its first item, not above that item: an attribute line between two
 * items ends the list (carve-php#4628).
 */
final class ADjotListWithAnInvisibleLeadStaysOneListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function listProvider(): array
    {
        return [
            'the ticket' => [
                "- r[^n]\n\n- [^n]: {.c}\n  foo\n",
                '<ul><li><p>r<sup>1</sup></p></li><li><p class="c">foo</p></li></ul>',
            ],
            'a third item' => [
                "- a\n\n- b\n\n- [^n]: {.c}\n  foo\n",
                '<ul><li><p>a</p></li><li><p>b</p></li><li><p class="c">foo</p></li></ul>',
            ],
            'an ordered list' => [
                "1. r[^n]\n\n2. [^n]: {.c}\n   foo\n",
                '<ol><li><p>r<sup>1</sup></p></li><li><p class="c">foo</p></li></ol>',
            ],
            'the invisible lead first' => [
                "- [^n]: {.c}\n  foo\n\n- r[^n]\n",
                '<ul><li><p class="c">foo</p></li><li><p>r<sup>1</sup></p></li></ul>',
            ],
            'after a paragraph' => [
                "p\n\n- r\n\n- [^n]: {.c}\n  foo\n\n[^n]: x\n",
                '<p>p</p><ul><li><p>r</p></li><li><p class="c">foo</p></li></ul>',
            ],
        ];
    }

    #[DataProvider('listProvider')]
    public function testTheListStaysOneLooseList(string $djot, string $html): void
    {
        $this->assertSame($html, $this->render($djot));
    }

    public function testANoteBodyAboveTheListStaysOutOfIt(): void
    {
        $carve = (new DjotToCarve())->convert("- [^n]: note\n    more\n  {.a}\n  [r]: /u\n\nx[^n] [x][r]");

        $this->assertStringContainsString("[^n]: note\n  more\n", $carve);
        $this->assertStringContainsString("{loose}\n- %%", $carve);
    }

    private function render(string $djot): string
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($djot));
        $html = (string)preg_replace(['/<section role="doc-endnotes".*$/s', '/<a [^>]*role="doc-noteref"[^>]*>(.*?)<\/a>/', '/\s+id="[^"]*"/'], ['', '$1', ''], $html);

        return trim((string)preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $html));
    }
}
