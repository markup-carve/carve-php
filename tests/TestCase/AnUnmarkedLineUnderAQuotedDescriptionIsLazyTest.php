<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnUnmarkedLineUnderAQuotedDescriptionIsLazyTest extends TestCase
{
    use RuleATodo;

    #[DataProvider('cases')]
    public function testQuotedDescription(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);
        $this->assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $actual));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'a heading at column zero leaves the description' => [
                ":: t\n: > quote\n# Heading\n",
                '<dl><dt>t</dt><dd><blockquote><p>quote</p></blockquote></dd></dl><section id="Heading"><h1>Heading</h1></section>',
            ],
            'an indented heading stays in the quote paragraph' => [
                ":: t\n: > quote\n # Heading\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote><p>quote</p></blockquote>\n  </dd>\n</dl>\n<section id=\"Heading\">\n  <h1>Heading</h1>\n</section>",
            ],
            'c016' => [
                '> :: t
> : para
  # H
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
            ],
            'c019' => [
                '> :: t
> : para
    ---
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>",
            ],
            'c021' => [
                '> :: t
> : para
    [r]: /u
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p>tail</p>",
            ],
            'c032' => [
                '> :: t
> : para
>   more
>  [r]: /u
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para\nmore</dd>\n  </dl>\n</blockquote>",
            ],
            'c051' => [
                '> :: t
> : para
    [^p]: n
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>",
            ],
            'c063' => [
                '> :: t
> : para
     # H
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>tail</p>\n</section>",
            ],
            'c067' => [
                '> :: t
> : para
  | a |
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            ],
            'c068' => [
                '> :: t
> : para
     ---
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>",
            ],
            'c070' => [
                '> :: t
> : para
        | a |
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>tail</p>",
            ],
            'c073' => [
                '> :: t
> : para
        {#x}
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p id=\"x\">tail</p>",
            ],
            'c074' => [
                '> :: t
> : para
        {#x}
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>",
            ],
            'c083' => [
                '> :: t
> : para
        ---
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>\n<p>tail</p>",
            ],
            'c086' => [
                '> :: t
> : para
 ---
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>",
            ],
            'c089' => [
                '> :: t
> : para
        {#x}
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p id=\"x\">tail</p>",
            ],
            'c093' => [
                '> :: t
> : para
  | a |
x
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>x</p>",
            ],
            'c096' => [
                '> :: t
> : para
        # H
    tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H
tail</dd>
</dl>
</blockquote>',
            ],
            'c097' => [
                '> :: t
```
', '<blockquote>
<dl>
<dt>t</dt>
</dl>
</blockquote>
<pre><code></code></pre>',
            ],
            'c100' => [
                '> :: t
> : para
    ---
x
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>\n<p>x</p>",
            ],
            'c102' => [
                '> :: t
> : para
   ---
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>\n<p>tail</p>",
            ],
            'c108' => [
                '> :: t
> : para
    {#x}
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>",
            ],
            'c109' => [
                '> :: t
> : para
        | a |
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            ],
            'c112' => [
                '> :: t
> : para
  {#x}
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p id=\"x\">tail</p>",
            ],
            'c113' => [
                '> :: t
> : para
   # H
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
            ],
            'c117' => [
                '> :: t
> : para
  {#x}
x
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p id=\"x\">x</p>",
            ],
            'c120' => [
                '> :: t
> : para
    | a |
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
| a |
tail</dd>
</dl>
</blockquote>',
            ],
            'c130' => [
                '> :: t
> : para
>  - li
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para</dd>
</dl>
<ul>
<li>li</li>
</ul>
</blockquote>',
            ],
            'c131' => [
                '> :: t
> : para
  ---
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<hr>",
            ],
            'c136' => [
                '> :: t
> : para
 # H
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
            ],
            'c140' => [
                '> :: t
> : para
    # H
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
            ],
            'c147' => [
                '> :: t
> : para
     {#x}
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>",
            ],
            'c148' => [
                '> :: t
> : para
        [r]: /u
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p>tail</p>",
            ],
            'c151' => [
                '> :: t
> : para
    | a |
    tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>tail</p>",
            ],
            'c158' => [
                '> :: t
> : para
  [r]: /u
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<p>tail</p>",
            ],
            'c172' => [
                '> :: t
> : para
        [^p]: n
tail
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>",
            ],
            'c187' => [
                '> :: t
> : para
  # H
x
', "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para</dd>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>x</p>\n</section>",
            ],
            'c188' => [
                '> :: t
> : para
   {#x}
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
{<span class="tag"><strong>#x</strong></span>}</dd>
</dl>
</blockquote>',
            ],
            'marked-heading' => [
                '> :: t
> : para
> # H
', '<blockquote><dl><dt>t</dt><dd>para</dd></dl><h1 id="H">H</h1></blockquote>',
            ],
            'marked-deep-heading' => [
                '> :: t
> : para
>     # H
', '<blockquote><dl><dt>t</dt><dd><p>para</p><h1 id="H">H</h1></dd></dl></blockquote>',
            ],
            'marked-table' => [
                '> :: t
> : para
> | a |
', '<blockquote><dl><dt>t</dt><dd>para</dd></dl><table><tbody><tr><td>a</td></tr></tbody></table></blockquote>',
            ],
        ];
    }
}
