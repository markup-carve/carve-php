<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#3421 follow-up rulings (22 October), ported from carve-js.
 */
class TheCarve3421FollowUpRulingsTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string}>
     */
    public static function taskStates(): iterable
    {
        foreach ([' ', 'x', 'X', '_', '-', '/', '!', '>', '?'] as $state) {
            yield "task state [$state]" => [$state];
        }
    }

    #[DataProvider('taskStates')]
    public function testStackedListFollowerIsRefusedForEveryTaskState(string $state): void
    {
        $html = (new CarveConverter())->convert("- [$state] - +\n    text\n");
        $this->assertStringContainsString("<li>+\ntext</li>\n    </ul>", $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readings(): iterable
    {
        // Ruling 1: a wrapped attribute opened at a container's column is one construct.
        yield 'wrapped attribute below an item' => ["- a\n  {.c\n}\npara\n", '<ul><li>a</li></ul><p>para</p>'];
        yield 'wrapped attribute after a blank' => ["- a\n\n  {.c\n}\npara\n", '<ul><li>a</li></ul><p>para</p>'];
        yield 'wrapped attribute below a description' => [":: t\n: d\n  {.c\n}\npara\n", '<dl><dt>t</dt><dd>d</dd></dl><p>para</p>'];

        // Ruling 2: a quote follower nests inside the innermost quote; an intervening column is refused.
        yield 'quote follower at column 0' => ["- > > +\n> q\n", '<ul><li><blockquote><blockquote><p>+</p></blockquote></blockquote></li></ul><blockquote><p>q</p></blockquote>'];
        yield 'hosted quote follower at column 0' => ["> - > > +\n> > q\n", '<blockquote><ul><li><blockquote><blockquote><p>+</p></blockquote></blockquote></li></ul><blockquote><p>q</p></blockquote></blockquote>'];
        yield 'quote follower at an intervening column' => ["- > > +\n  > q\n", '<ul><li><blockquote><blockquote><p>q</p></blockquote></blockquote></li></ul>'];
        yield 'heading at an intervening column' => ["- > > +\n  # h\n", '<ul><li><blockquote><blockquote><p>+</p></blockquote></blockquote><h1 id="h">h</h1></li></ul>'];
        yield 'hosted follower at an intervening column' => ["> - > > +\n>   b\n", '<blockquote><ul><li><blockquote><blockquote><p>+b</p></blockquote></blockquote></li></ul></blockquote>'];
        yield 'follower at no opener column' => ["- - > +\n b\n", '<ul><li><ul><li><blockquote><p>+b</p></blockquote></li></ul></li></ul>'];

        // Ruling 3: a hosted stacked opener takes any non-list block at document column 0.
        yield 'hosted heading' => ["> - > +\n# h\n", '<blockquote><ul><li><blockquote><p>+</p></blockquote></li></ul></blockquote><section id="h"><h1>h</h1></section>'];
        yield 'hosted table' => ["> - > +\n| c |\n", '<blockquote><ul><li><blockquote><p>+</p></blockquote></li></ul></blockquote><table><tbody><tr><td>c</td></tr></tbody></table>'];
        yield 'hosted heading after a sibling heading' => ["> - a\n> # t\n> - > +\n# h\n", '<blockquote><ul><li>a</li></ul><h1 id="t">t</h1><ul><li><blockquote><p>+</p></blockquote></li></ul></blockquote><section id="h"><h1>h</h1></section>'];
        yield 'hosted list follower folds' => ["> - - > +\n- s\n", '<blockquote><ul><li><ul><li><blockquote><p>+- s</p></blockquote></li></ul></li></ul></blockquote>'];

        yield 'wrapped attribute in a nested item' => ["- a\n  - b\n    {.c\n}\npara\n", '<ul><li>a<ul><li>b</li></ul></li></ul><p>para</p>'];
        yield 'wrapped attribute below the column folds' => ["- a\n {.c\n}\npara\n", '<ul><li>a{.c}para</li></ul>'];
        yield 'dangling wrapped attribute loosens' => ["- a\n\n  {.c\n}\n- s\n", '<ul><li><p>a</p></li><li><p>s</p></li></ul>'];
        yield 'attribute follower of a quote plus' => ["> +\n{.c}\npara\n", '<blockquote><p class="c">para</p></blockquote>'];
        yield 'unclosed fence follower of a quote plus' => ["> +\n```\nx\n\ny\n", '<blockquote><pre><code>x</code></pre></blockquote><p>y</p>'];
        yield 'stacked list plus at an inner column' => ["- - +\n  # h\n", '<ul><li><ul><li><h1 id="h">h</h1></li></ul></li></ul>'];
        yield 'hosted heading after a lazy item line' => ["> - a\n> b\n> - > +\n# h\n", '<blockquote><ul><li>ab</li><li><blockquote><p>+</p></blockquote></li></ul></blockquote><section id="h"><h1>h</h1></section>'];
        yield 'opener folded under a heading item stays text' => ["> - # a\n> b\n> - > +\n# h\n", '<blockquote><ul><li><h1 id="a">a</h1></li></ul><p>b- &gt; +</p></blockquote><section id="h"><h1>h</h1></section>'];

        yield 'unclosed fence keeps a quoted payload line' => ["- > +\n```\n> x\n", '<ul><li><blockquote><p>+</p></blockquote></li></ul><pre><code>&gt; x</code></pre>'];
        yield 'list plus unclosed fence keeps a quoted payload line' => ["- +\n```\n> x\n\ny\n", '<ul><li><pre><code>&gt; x</code></pre></li></ul><p>y</p>'];

        yield 'unclosed fence takes the rest of an indented list' => ["  - +\n```\n- x\n", '<ul><li>+</li></ul><pre><code>- x</code></pre>'];
        yield 'unclosed fence takes following list lines' => ["- +\n```\n- x\n- y\n", '<ul><li><pre><code>- x- y</code></pre></li></ul>'];
        yield 'unclosed fence ends at a sibling when a blank follows' => ["- +\n```\nx\n- y\n\npara\n", '<ul><li><pre><code>x</code></pre></li><li>y</li></ul><p>para</p>'];

        yield 'unclosed raw fence takes the rest' => ["- +\n```=html\nx\n- y\n", '<ul><li>x- y</li></ul>'];
        yield 'indented fence run is payload, not a closer' => ["- +\n```\n  ```\n- y\n", '<ul><li><pre><code>  ```- y</code></pre></li></ul>'];

        // Ruling 4: the owning quote's column stays eligible.
        yield 'owning quote column' => ["- > > +\n    b\n", '<ul><li><blockquote><blockquote><p>+b</p></blockquote></blockquote></li></ul>'];
    }

    #[DataProvider('readings')]
    public function testReading(string $source, string $html): void
    {
        $actual = (new CarveConverter())->convert($source);

        $this->assertSame($html, implode('', array_map('trim', explode("\n", trim($actual)))));
    }

    /**
     * @return iterable<string, array{string, array<string>}>
     */
    public static function lintCases(): iterable
    {
        // Ruling 5: a document-level `+` no container claims is reported when a block follows.
        yield 'before a fence' => ["q\n+\n```\nx\n```\n", ['2:1']];
        yield 'before a quote' => ["q\n+\n> x\n", ['2:1']];
        yield 'before a thematic break' => ["q\n+\n---\n", ['2:1']];
        yield 'first line before a heading' => ["+\n# h\n", ['1:1']];
        yield 'between text lines' => ["+\npara\n", []];
        yield 'before a comment' => ["q\n+\n%% c\n", []];
        yield 'before an attribute' => ["q\n+\n{.c}\npara\n", ['2:1']];
        yield 'inside a div' => ["::: d\nq\n+\n# h\n:::\n", []];
        yield 'quoted plus before a sibling item' => ["> q\n> +\n> - s\n", ['2:3']];
        yield 'hosted plus at an intervening column' => ["> - > > +\n>   b\n", ['1:9']];
        yield 'hosted plus at an inner item column' => ["> - - > +\n>   b\n", ['1:9']];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('lintCases')]
    public function testLint(string $source, array $expected): void
    {
        $actual = [];
        foreach ((new DocumentLinter())->lint($source) as $warning) {
            if ($warning->rule === 'continuation-marker-refused') {
                $actual[] = $warning->line . ':' . $warning->column;
            }
        }

        $this->assertSame($expected, $actual);
    }
}
