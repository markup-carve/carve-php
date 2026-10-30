<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ownership walk reads fences, terms and `+` lines as the spec model's
 * `ownLines()` does (carve-php#4242). Expectations are the spec model's HTML at
 * carve main `dd86f2eb`; the last four rows guard readings the walk must keep.
 */
class TheWalkReadsFencesAndTermsAsTheSpecModelTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function rows(): array
    {
        return [
            'an item after a bare term keeps its div' => [
                ":: t\n- a\n  :::\n",
                "<dl><dt>t</dt></dl><ul><li>a\n    <div></div></li></ul>",
            ],
            'an item after a bare term keeps its quote' => [
                ":: t\n- a\n  > q\n",
                "<dl><dt>t</dt></dl><ul><li>a\n    <blockquote><p>q</p></blockquote></li></ul>",
            ],
            'an ordered item after a bare term keeps its div' => [
                ":: t\n1. a\n   :::\n",
                "<dl><dt>t</dt></dl><ol><li>a\n    <div></div></li></ol>",
            ],
            'a quoted item after a bare term keeps its break' => [
                "> :: t\n> - a\n>   ---\n",
                "<blockquote><dl><dt>t</dt></dl><ul><li>a\n      <hr></li></ul></blockquote>",
            ],
            'a fence under an item after a term' => [
                ":: t\n- :  - a\n     ```\n     [r]: /url\n     ```\n\nSee [r][].\n",
                "<dl><dt>t</dt></dl><ul><li>:  - a\n    <pre><code>[r]: /url\n</code></pre></li></ul><p>See [r][].</p>",
            ],
            'a div with a definition under an item after a term' => [
                ":: t\n- :  a\n   ::: note\n    [r]: /url\n   :::\n\n[r][]\n",
                "<dl><dt>t</dt></dl><ul><li>:  a\n    <aside class=\"admonition note\" aria-label=\"Note\"></aside></li></ul><p><a href=\"/url\">r</a></p>",
            ],
            'a closer past the opener is payload' => [
                "- item\n\n    ```\n    a\n      ```\n      \n    b\n    ```\n",
                "<ul><li>item\n    <pre><code>a\n  ```\n  \nb\n</code></pre></li></ul>",
            ],
            'a closer past the opener is payload in a doubly quoted item' => [
                "> > - a\n> >     ~~~\n> >       ~~~\n> >       ```\n> >   ```\n> >   ~~~\nafter\n",
                "<blockquote><blockquote><ul><li>a\n        <pre><code>  ~~~\n  ```\n```\n</code></pre></li></ul></blockquote></blockquote><p>after</p>",
            ],
            'a plus whose follower is off its column stays text' => [
                "- outer\n  - inner\n ```\n  +\n  ```\nnext\n",
                "<ul><li>outer\n    <ul><li>inner</li></ul></li></ul><pre><code> +\n ```\nnext\n</code></pre>",
            ],
            'a plus with a quote follower in the next item' => [
                "- a\n> \n  - b\n  +\n> q\n",
                "<ul><li>a</li></ul><blockquote></blockquote><ul><li>b\n+</li></ul><blockquote><p>q</p></blockquote>",
            ],
            'a note list keeps its fenced child' => [
                "- [^n]: :: term\n    - child\n      ```\n      code\n      ```\nafter [^n]\n",
                "<ul><li></li></ul><p>after <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p><section role=\"doc-endnotes\" aria-label=\"Footnotes\"><hr><ol><li id=\"fn1\"><dl><dt>term</dt></dl><ul><li>child\n          <pre><code>code\n</code></pre></li></ul><p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p></li></ol></section>",
            ],
            'a tabbed div payload keeps its columns' => [
                "- a\n  :::\n   \t{.c}\n \t ```\n        `````\n \t```php\n  \t``` x\n \t1. n\n  \t\t> q\n   - s\nafter\n",
                "<ul><li>a\n    <div><pre class=\"c\"><code>   `````\n```php\n``` x\n1. n\n   &gt; q\n- s\n</code></pre></div></li></ul><p>after</p>",
            ],
            'a description text fence does not pair with a moved opener' => [
                ":: k\n: d\n   ````\n   a\n\n    ````\n   b\n   ```\n",
                "<dl><dt>k</dt><dd><p>d\n<code>\na</code></p><pre><code>b\n```\n</code></pre></dd></dl>",
            ],
            'a nested description still opens its fence' => [
                ":: p\n:  :: t\n   :  ::: x\n      ~~~\n      code\n      ~~~\n",
                "<dl><dt>p</dt><dd><dl><dt>t</dt><dd><div class=\"x\"><pre><code>code\n</code></pre></div></dd></dl></dd></dl>",
            ],
            'a framed fence keeps the list tight' => [
                "- x\n  para\n  ```\n     ```\n\n  y\n",
                "<ul><li>x\npara\n    <pre><code>   ```\n\ny\n</code></pre></li></ul>",
            ],
            'a refused plus before a quote off its column' => [
                "- a\n- b\n+\n > q\ntail\n",
                "<ul><li>a</li><li>b\n+</li></ul><blockquote><p>q\ntail</p></blockquote>",
            ],
        ];
    }

    #[DataProvider('rows')]
    public function testHtml(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
    }
}
