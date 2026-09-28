<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<p>` in an item is what makes an imported list loose. A second block is not.
 *
 * The HTML import contract, under "Lists keep the source's tightness", rules
 * that a bare-text `<li>` imports as a TIGHT item and only `<li><p>...</p></li>`
 * stays loose. This importer also counted a paragraph or a figure standing
 * SECOND inside an item, and bare text beside a heading arrives as one - so the
 * expected HTML of CommonMark 0.31.2 example 300, whose items hold no `<p>` at
 * all, imported loose and the round trip added a `<p>` the source never had
 * (carve-php#2642).
 *
 * The vote still reaches a `<p>` under an unsupported wrapper, because such an
 * element gives way to its children and the paragraph is then the item's own.
 */
class AListItemWithNoParagraphIsTightTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lists(): array
    {
        return [
            'the expected HTML of CommonMark example 300' => [
                "<ul>\n<li>\n<h1>Foo</h1>\n</li>\n<li>\n<h2>Bar</h2>\nbaz</li>\n</ul>\n",
                "- # Foo\n- ## Bar\n  baz\n",
            ],
            'bare text beside a heading in one item' => [
                '<ul><li><h2>Bar</h2>baz</li></ul>',
                "- ## Bar\n  baz\n",
            ],
            'two headings and two runs of text' => [
                '<ul><li><h1>a</h1>b<h2>c</h2>d</li></ul>',
                "- # a\n  b\n  ## c\n  d\n",
            ],
            'a code block after bare text' => [
                '<ul><li>a<pre><code>x</code></pre></li><li>b</li></ul>',
                "- a\n  ```\n  x\n  ```\n- b\n",
            ],
            'a quote after bare text' => [
                '<ul><li>text<blockquote>q</blockquote></li><li>two</li></ul>',
                "- text\n  > q\n- two\n",
            ],
            'a sublist after bare text' => [
                '<ul><li>one<ul><li>sub</li></ul></li><li>two</li></ul>',
                "- one\n  - sub\n- two\n",
            ],
            // The three shapes the contract spells out, unchanged.
            'bare text in every item' => [
                '<ul><li>one</li><li>two</li></ul>',
                "- one\n- two\n",
            ],
            'a paragraph in every item' => [
                '<ul><li><p>one</p></li><li><p>two</p></li></ul>',
                "- one\n\n- two\n",
            ],
            'one paragraph item loosens the list' => [
                '<ul><li>one</li><li><p>two</p></li></ul>',
                "- one\n\n- two\n",
            ],
            'a one-item paragraph list takes the key' => [
                '<ul><li><p>only</p></li></ul>',
                "{loose}\n- only\n",
            ],
            'two paragraphs in one item' => [
                '<ol><li><p>one</p><p>two</p></li></ol>',
                "1. one\n\n   two\n",
            ],
            // An unsupported element gives way to its children, so the `<p>`
            // under it votes as the item's own.
            'a paragraph under an unsupported wrapper' => [
                '<ul><li><x-a><p>first</p><p>second</p></x-a></li></ul>',
                "- first\n\n  second\n",
            ],
            'a paragraph under two unsupported wrappers' => [
                '<ul><li><x-a><x-b><p>first</p></x-b></x-a></li><li>two</li></ul>',
                "- first\n\n- two\n",
            ],
            'an unsupported wrapper holding no paragraph keeps the list tight' => [
                '<ul><li><x-a><h2>Bar</h2></x-a></li><li>two</li></ul>',
                "- ## Bar\n- two\n",
            ],
        ];
    }

    #[DataProvider('lists')]
    public function testTheListKeepsTheSourceTightness(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    /**
     * The tree is where the decision lives, and `tight` is the only field that
     * moved: a wrong reading here adds a paragraph on the way back out.
     *
     * @return array<string, array{string, bool}>
     */
    public static function trees(): array
    {
        return [
            'no paragraph anywhere' => [
                "<ul>\n<li>\n<h1>Foo</h1>\n</li>\n<li>\n<h2>Bar</h2>\nbaz</li>\n</ul>\n",
                true,
            ],
            'a paragraph in the second item' => ['<ul><li>one</li><li><p>two</p></li></ul>', false],
            'a paragraph under an unsupported wrapper' => [
                '<ul><li><x-a><p>first</p></x-a></li><li>two</li></ul>',
                false,
            ],
        ];
    }

    #[DataProvider('trees')]
    public function testTheImportedTreeCarriesTheTightness(string $html, bool $tight): void
    {
        $children = (new HtmlToCarve())->convertToAst($html)['children'];
        $this->assertIsArray($children);
        $list = $children[0];
        $this->assertIsArray($list);

        $this->assertSame('list', $list['type'] ?? null);
        $this->assertSame($tight, $list['tight'] ?? null);
    }
}
