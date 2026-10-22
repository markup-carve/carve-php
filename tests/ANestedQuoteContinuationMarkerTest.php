<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedQuoteContinuationMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        $nested = '<blockquote><blockquote><p>q</p><p>para</p></blockquote></blockquote>';
        $refused = '<blockquote><blockquote><p>q +</p></blockquote></blockquote>';

        return [
            'prefixed attached paragraph' => ["> > q\n> +\n> para\n", $nested],
            'unprefixed attached paragraph' => ["> > q\n> +\npara\n", '<blockquote><blockquote><p>q + para</p></blockquote></blockquote>'],
            'marker at end of input' => ["> > q\n> +\n", $refused],
            'blank line ends the quote' => ["> > q\n> +\n\npara\n", $refused . '<p>para</p>'],
            'nested quote in a list item' => ["- > > q\n  > +\n\npara\n", '<ul><li><blockquote><blockquote><p>q +</p></blockquote></blockquote></li></ul><p>para</p>'],
            'plus at inner content column is text' => ["> > q\n> > +\n\npara\n", '<blockquote><blockquote><p>q +</p></blockquote></blockquote><p>para</p>'],
            'unnested unprefixed marker' => ["> q\n+\npara\n", '<blockquote><p>q</p><p>para</p></blockquote>'],
            'leading plus is paragraph text' => ["> +\n\npara\n", '<blockquote><p>+</p></blockquote><p>para</p>'],
            'plus in a quoted list item' => ["> - a\n> +\n> para\n", '<blockquote><ul><li>a para</li></ul></blockquote>'],
            'attached quote nests in inner quote' => ["> > q\n> +\n> > r\n", '<blockquote><blockquote><p>q</p><blockquote><p>r</p></blockquote></blockquote></blockquote>'],
            'empty inner quote owns attached quote' => ["> >\n> +\n> > q\n", '<blockquote><blockquote><blockquote><p>q</p></blockquote></blockquote></blockquote>'],
            'attached quote keeps a fenced block and following paragraph' => ["> > q\n> +\n> > ```php\n> > x\n> > ```\n> > after\n", '<blockquote><blockquote><p>q</p><blockquote><pre><code class="language-php">x</code></pre><p>after</p></blockquote></blockquote></blockquote>'],
            'deeper quoted fence line is code content' => ["> > q\n> +\n> > ```\n> > > ```\n> > x\n> > ```\n", '<blockquote><blockquote><p>q</p><blockquote><pre><code>&gt; ``` x</code></pre></blockquote></blockquote></blockquote>'],
            'attached table keeps both rows' => ["> > - a\n> +\n> | a |\n> | b |\n", '<blockquote><blockquote><ul><li>a</li></ul><table><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table></blockquote></blockquote>'],
            'attached list keeps sibling items' => ["> > - a\n> +\n> - s\n> - t\n", '<blockquote><blockquote><ul><li><p>a</p></li><li><p>s</p></li><li><p>t</p></li></ul></blockquote></blockquote>'],
            'attached quote nests in item' => ["> - a\n> +\n> > r\n", '<blockquote><ul><li>a<blockquote><p>r</p></blockquote></li></ul></blockquote>'],
            'attached quote nests under an indented item' => ["- a\n  > > q\n  > +\n  > > r\n", '<ul><li>a<blockquote><blockquote><p>q</p><blockquote><p>r</p></blockquote></blockquote></blockquote></li></ul>'],
            'inner quote owns paragraph after list' => ["> > - a\n> +\n> para\n", '<blockquote><blockquote><ul><li>a</li></ul><p>para</p></blockquote></blockquote>'],
            'blank quote line retains item owner' => ["> - a\n>\n> +\n> para\n", '<blockquote><ul><li>a para</li></ul></blockquote>'],
            'paragraph cannot own marker' => ["> a\n> +\n> > q\n", '<blockquote><p>a +</p><blockquote><p>q</p></blockquote></blockquote>'],
            'marker inside inner quote is text' => ["> > q\n> > +\n> > - s\n", '<blockquote><blockquote><p>q + - s</p></blockquote></blockquote>'],
            'glued single marker is text' => [">+\nq\n", '<p>&gt;+ q</p>'],
            'glued double marker is text' => [">>+\nq\n", '<p>&gt;&gt;+ q</p>'],
            'glued double marker with space is text' => [">> +\nq\n", '<p>&gt;&gt; + q</p>'],
            'glued inner marker is quote text' => ["> >+\nq\n", '<blockquote><p>&gt;+ q</p></blockquote>'],
            'first block marker inside nested quote' => ["> > +\nq\n", '<blockquote><blockquote><p>q</p></blockquote></blockquote>'],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testNestedQuoteContinuationMarker(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);
        $html = (string)preg_replace('/\s+/', ' ', $html);
        $html = str_replace('> <', '><', $html);
        $html = (string)preg_replace('/\s+(?=<blockquote>)/', '', $html);
        $html = (string)preg_replace('/\s+(?=<\/)/', '', $html);

        self::assertSame($expected, trim($html));
    }

    public function testGluedPlusReceivesBlockquoteSpacingLint(): void
    {
        $rules = array_column((new SourceLinter())->lint(">+\n"), 'rule');

        self::assertContains('blockquote-marker-without-space', $rules);
    }

    public function testUnprefixedPlusDoesNotAttachListInsideQuote(): void
    {
        $source = "- a\n  > q\n  +\n  - b\n";
        $expected = "<ul>\n  <li>a\n    <blockquote><p>q\n+\n- b</p></blockquote>\n  </li>\n</ul>\n";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testFirstBlockPlusDoesNotAttachListInsideListItem(): void
    {
        $source = "- x\n\n  > +\n  - a\n";
        $expected = "<ul>\n  <li>x\n    <blockquote><p>+\n- a</p></blockquote>\n  </li>\n</ul>\n";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testMarkerAfterBlankCannotReachIndentedQuotedList(): void
    {
        $source = "> - a\n\n>   - b\n>     - c\n> +\n> > z\n";
        $expected = "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n"
            . "<blockquote>\n  <ul>\n    <li>b\n      <ul>\n        <li>c\n+</li>\n      </ul>\n    </li>\n  </ul>\n  <blockquote><p>z</p></blockquote>\n</blockquote>\n";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testShallowerLineEndsAttachedQuotedFence(): void
    {
        $source = "> > q\n> +\n> > ```\n> x\n> > ```\n";
        $expected = "<blockquote>\n  <blockquote>\n    <p>q</p>\n    <blockquote>\n"
            . "      <pre><code></code></pre>\n    </blockquote>\n  </blockquote>\n"
            . "  <p>x</p>\n  <blockquote>\n    <pre><code></code></pre>\n  </blockquote>\n</blockquote>\n";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
