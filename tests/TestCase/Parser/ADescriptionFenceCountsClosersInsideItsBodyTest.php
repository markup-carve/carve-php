<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADescriptionFenceCountsClosersInsideItsBodyTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function fenceProvider(): array
    {
        $body = "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code></code></pre>\n</dd>\n</dl>";

        return [
            'body-column closer after a tab opener' => [":: t\n: a\n\t```\n  ```\n", $body],
            'below-column payload before the closer' => [
                ":: m\n: l\n     ```\ne\n     ```\n",
                "<dl>\n<dt>m</dt>\n<dd>\n<p>l</p>\n<pre><code>e\n</code></pre>\n</dd>\n</dl>",
            ],
            'one-column payload inside an overindented fence' => [
                ":: t\n: a\n   ```\n   b\n y\n   ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>b\ny\n</code></pre>\n</dd>\n</dl>",
            ],
            'other fence character is payload' => [
                ":: t\n: a\n   ```\n~~~\n   ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>~~~\n</code></pre>\n</dd>\n</dl>",
            ],
            'indented attribute inside an open fence' => [
                ":: t\n: a\n   ```\n {.a}\n   ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>{.a}\n</code></pre>\n</dd>\n</dl>",
            ],
            'indented competing fence inside an open fence' => [
                ":: t\n: a\n   ```\n  ~~~\n   ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>~~~\n</code></pre>\n</dd>\n</dl>",
            ],
            'tilde fence with a backtick payload' => [
                ":: t\n: a\n   ~~~\n  ```\n   ~~~\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>```\n</code></pre>\n</dd>\n</dl>",
            ],
            'competing fence in a list at depth two' => [
                "- x\n  - y\n    :: t\n    : a\n       ```\n      ~~~\n       ```\n",
                "<ul>\n<li>x\n<ul>\n<li>y\n<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>~~~\n</code></pre>\n</dd>\n</dl>\n</li>\n</ul>\n</li>\n</ul>",
            ],
            'indented attribute in a quoted fence' => [
                "> :: t\n> : a\n>    ```\n>  {.a}\n>    ```\n",
                "<blockquote>\n<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>{.a}\n</code></pre>\n</dd>\n</dl>\n</blockquote>",
            ],
            'payload past the opener keeps its extra column' => [
                ":: t\n: a\n   ```\n    x\n   ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code> x\n</code></pre>\n</dd>\n</dl>",
            ],
            'a below-column attribute ends the body' => [
                ":: t\n: a\n   ```\n{.c}\n  ```\n",
                "<dl>\n<dt>t</dt>\n<dd>a\n<code></code></dd>\n</dl>\n<p class=\"c\"><code></code></p>",
            ],
            'indented heading text is payload' => [
                ":: t\n: a\n    ```\n # y\n  ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code># y\n</code></pre>\n</dd>\n</dl>",
            ],
            'a below-column div opener ends the body' => [
                ":: t\n: a\n    ```\n ::: y\n    ```\n",
                "<dl>\n<dt>t</dt>\n<dd>a\n<code></code></dd>\n</dl>\n<p>::: y\n<code></code></p>",
            ],
            'lazy paragraph before the fence' => [
                ":: t\n: a\nq\n\t```\n  x\n  ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a\nq</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>",
            ],
            'payload between the body and opener columns' => [
                ":: t\n: a\nq\n\t```\n   x\n  ```\n",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>a\nq</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>",
            ],
            'bullet wrapper' => [
                "- x\n  :: t\n  : a\n      ```\n    ```\n",
                "<ul>\n<li>x\n{$body}\n</li>\n</ul>",
            ],
            'bullet wrapper with a below-column payload' => [
                "- :: t\n  : a\n     ```\n x\n     ```\n",
                "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n</li>\n</ul>",
            ],
            'bullet wrapper with a column-zero payload' => [
                "- :: t\n  : a\n     ```\nx\n     ```\n",
                "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>a</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n</li>\n</ul>",
            ],
            'ordered wrapper' => [
                ". x\n  :: t\n  : a\n      ```\n    ```\n",
                "<ol>\n<li>x\n{$body}\n</li>\n</ol>",
            ],
            'task wrapper' => [
                "- [ ] x\n  :: t\n  : a\n      ```\n    ```\n",
                "<ul class=\"task-list\">\n<li><input type=\"checkbox\" disabled aria-label=\"x\"> x\n{$body}\n</li>\n</ul>",
            ],
            'quote wrapper' => [
                "> :: t\n> : a\n>     ```\n>   ```\n",
                "<blockquote>\n{$body}\n</blockquote>",
            ],
            'quote wrapper with below-column payload' => [
                "> :: m\n> : l\n>      ```\n> e\n>      ```\n",
                "<blockquote>\n<dl>\n<dt>m</dt>\n<dd>\n<p>l</p>\n<pre><code>e\n</code></pre>\n</dd>\n</dl>\n</blockquote>",
            ],
            'two list depths' => [
                "- x\n  - y\n    :: t\n    : a\n        ```\n      ```\n",
                "<ul>\n<li>x\n<ul>\n<li>y\n{$body}\n</li>\n</ul>\n</li>\n</ul>",
            ],
            'one-column control' => [":: t\n: a\n  ```\n  ```\n", $body],
            'nested separator div holds a code fence' => [
                "{loose}\n:: parent\n\n:  {loose}\n   :: %%\n   :  ::: x\n      ```\n      code\n      ```\n",
                "<dl>\n<dt>parent</dt>\n<dd>\n<dl>\n<dt></dt>\n<dd>\n<div class=\"x\">\n<pre><code>code\n</code></pre>\n</div>\n</dd>\n</dl>\n</dd>\n</dl>",
            ],
            'nested separator div holds a tilde fence' => [
                ":: p\n:  :: t\n   :  ::: x\n      ~~~\n      code\n      ~~~\n",
                "<dl>\n<dt>p</dt>\n<dd>\n<dl>\n<dt>t</dt>\n<dd>\n<div class=\"x\">\n<pre><code>code\n</code></pre>\n</div>\n</dd>\n</dl>\n</dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('fenceProvider')]
    public function testACloserInsideTheBodyInterruptsItsParagraph(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        $insidePre = false;
        $lines = [];
        foreach (explode("\n", trim($html)) as $line) {
            if ($insidePre || trim($line) !== '') {
                $lines[] = $insidePre ? $line : trim($line);
            }
            if (str_contains($line, '<pre')) {
                $insidePre = true;
            }
            if (str_contains($line, '</pre>')) {
                $insidePre = false;
            }
        }

        $this->assertSame($expected, implode("\n", $lines));
    }
}
