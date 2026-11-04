<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quoted line four or more columns into an open quote paragraph continues it
 * in CommonMark, whatever its shape. Carve has no indented code, so the import
 * escapes the shape instead of keeping the indent (carve-php#4626).
 *
 * Expected HTML measured against cmark-gfm 0.29.0.gfm.13.
 */
final class AQuotedLineFourColumnsInContinuesTheQuoteParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function continuationProvider(): array
    {
        return [
            'a heading shape' => ["> foo\n>     # bar\n", '<blockquote><p>foo # bar</p></blockquote>'],
            'a deeper heading shape' => ["> foo\n>         ## bar\n", '<blockquote><p>foo ## bar</p></blockquote>'],
            'a quote marker' => ["> foo\n>     > bar\n", '<blockquote><p>foo &gt; bar</p></blockquote>'],
            'a break shape' => ["> foo\n>     ---\n", '<blockquote><p>foo ---</p></blockquote>'],
            'a nested quote' => [">> foo\n>>     # bar\n", '<blockquote><blockquote><p>foo # bar</p></blockquote></blockquote>'],
            'in a quote an item holds' => ["- > foo\n  >     # bar\n", '<ul><li><blockquote><p>foo # bar</p></blockquote></li></ul>'],
            'in a quote an ordered item holds' => ["1. > foo\n   >     # bar\n", '<ol><li><blockquote><p>foo # bar</p></blockquote></li></ol>'],
            'in a nested quote an item holds' => ["- > > foo\n  > >     # bar\n", '<ul><li><blockquote><blockquote><p>foo # bar</p></blockquote></blockquote></li></ul>'],
            'in a quote after the item paragraph' => ["- a\n\n  > foo\n  >     # bar\n", '<ul><li><p>a</p><blockquote><p>foo # bar</p></blockquote></li></ul>'],
            'then a spaced rule' => ["- > foo\n  >     # bar\n  > - - -\n", '<ul><li><blockquote><p>foo # bar</p><hr></blockquote></li></ul>'],
        ];
    }

    #[DataProvider('continuationProvider')]
    public function testTheLineContinuesTheParagraph(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'three columns in still interrupts' => ["> foo\n>    # bar\n", '<blockquote><p>foo</p><h1>bar</h1></blockquote>'],
            'after a heading it is code' => ["> # h\n>     # bar\n", '<blockquote><h1>h</h1><pre><code># bar </code></pre></blockquote>'],
            'after a quote blank it is code' => ["- > foo\n  >\n  >     code\n", '<ul><li><blockquote><p>foo</p><pre><code>code </code></pre></blockquote></li></ul>'],
            'under a list in the quote it interrupts the item' => ["- > - a\n  >     # b\n", '<ul><li><blockquote><ul><li>a <h1>b</h1></li></ul></blockquote></li></ul>'],
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
