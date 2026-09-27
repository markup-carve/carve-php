<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition term has no content column, so a block opener indented past
 * the enclosing container's content column is term text at every depth
 * (markup-carve/carve#2411). List markers keep Rule B and still open.
 */
final class ATermFoldsAnIndentedBlockOpenerAtEveryDepthTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'top level heading' => [":: c\n  # H\n", '<dl><dt>c# H</dt></dl>'],
            'quoted heading' => ["> :: c\n>   # H\n", '<blockquote><dl><dt>c# H</dt></dl></blockquote>'],
            'heading in a list item' => ["- item\n\n  :: c\n    # H\n", '<ul><li>item<dl><dt>c# H</dt></dl></li></ul>'],
            'heading after a bullet marker-line term' => [
                "- :: c\n    # H\n",
                '<ul><li><dl><dt>c# H</dt></dl></li></ul>',
            ],
            'heading after an ordered marker-line term' => [
                "1. :: c\n     # H\n",
                '<ol><li><dl><dt>c# H</dt></dl></li></ol>',
            ],
            'heading after a quoted marker-line term' => [
                "> - :: c\n>     # H\n",
                '<blockquote><ul><li><dl><dt>c# H</dt></dl></li></ul></blockquote>',
            ],
            'heading after a nested marker-line term' => [
                "- - :: c\n      # H\n",
                '<ul><li><ul><li><dl><dt>c# H</dt></dl></li></ul></li></ul>',
            ],
            'footnote after a marker-line term and comment stays unresolved' => [
                "x[^n]\n\n- :: c\n    %% note\n    [^n]: y\n",
                '<p>x[^n]</p><ul><li><dl><dt>c[^n]: y</dt></dl></li></ul>',
            ],
            'heading in a description' => [
                ":: a\n: b\n  :: c\n    # H\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c# H</dt></dl></dd></dl>',
            ],
            'note in a description' => [
                ":: a\n: b\n  :: c\n    ::: note\n    body\n    :::\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c::: notebody:::</dt></dl></dd></dl>',
            ],
            'second term in a description' => [
                ":: a\n: b\n  :: c\n    :: d\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c:: d</dt></dl></dd></dl>',
            ],
            'heading at the description column opens' => [
                ":: a\n: b\n  :: c\n  # H\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl><h1 id="H">H</h1></dd></dl>',
            ],
            'heading at column 0 opens' => [":: c\n# H\n", '<dl><dt>c</dt></dl><section id="H"><h1>H</h1></section>'],
            'comment line past the column keeps the term open' => [":: c\n  %% note\n  more\n", '<dl><dt>cmore</dt></dl>'],
            'comment fence past the column keeps its body hidden' => [
                ":: c\n  %%%\n  hidden\n  %%%\n  more\n",
                '<dl><dt>cmore</dt></dl>',
            ],
            'comment in a description' => [
                ":: a\n: b\n  :: c\n    %% note\n    # H\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c# H</dt></dl></dd></dl>',
            ],
            'comment at the column ends the term' => [":: c\n%% note\nmore\n", '<dl><dt>c</dt></dl><p>more</p>'],
            'link definition in a description is term text' => [
                "[t][r]\n\n:: a\n: b\n  :: c\n    [r]: /u\n",
                '<p>[t][r]</p><dl><dt>a</dt><dd><p>b</p><dl><dt>c[r]: /u</dt></dl></dd></dl>',
            ],
            'footnote definition in a list item is term text' => [
                "x[^n]\n\n- item\n\n  :: c\n    [^n]: y\n",
                '<p>x[^n]</p><ul><li>item<dl><dt>c[^n]: y</dt></dl></li></ul>',
            ],
            'link definition at the description column registers' => [
                "[t][r]\n\n:: a\n: b\n  :: c\n  [r]: /u\n",
                '<p><a href="/u">t</a></p><dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl></dd></dl>',
            ],
            'inline content does not reach across a folded comment' => [
                ":: a `code\n  %% note\n  end`\n",
                '<dl><dt>a <code>code</code>end<code></code></dt></dl>',
            ],
            'a list marker under a nested term ends it before a definition' => [
                "[t][r]\n\n:: a\n: b\n  :: c\n    - x\n    [r]: /u\n",
                '<p><a href="/u">t</a></p><dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl><ul><li>x</li></ul></dd></dl>',
            ],
            'a blank inside a folded comment fence keeps the term open' => [
                "[t][r]\n\n:: a\n: b\n  :: c\n    %%%\n\n    hidden\n    %%%\n    [r]: /u\n",
                '<p>[t][r]</p><dl><dt>a</dt><dd><p>b</p><dl><dt>c[r]: /u</dt></dl></dd></dl>',
            ],
            'an unclosed comment fence is a line comment, so a blank still ends the term' => [
                ":: a\n: b\n  :: c\n    %%% unclosed\n\n    [r]: /u\n\n[t][r]\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl></dd></dl><p><a href="/u">t</a></p>',
            ],
            'a percent-led folded comment line keeps its visible neighbors' => [
                ":: c\n  %% % note\n  visible\n  %% %\n  more\n",
                '<dl><dt>cvisiblemore</dt></dl>',
            ],
            'list marker opens' => [
                ":: a\n: b\n  :: c\n    - x\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl><ul><li>x</li></ul></dd></dl>',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendersAndFormatsToAFixedPoint(string $source, string $expected): void
    {
        $flat = static fn (string $html): string => (string)preg_replace('/\n\s*/', '', $html);
        $html = (new CarveConverter())->convert($source);
        $this->assertSame($expected, $flat($html));
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($html, (new CarveConverter())->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
