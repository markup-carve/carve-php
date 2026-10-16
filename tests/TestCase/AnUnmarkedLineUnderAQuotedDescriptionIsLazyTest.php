<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnUnmarkedLineUnderAQuotedDescriptionIsLazyTest extends TestCase
{
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
                '<dl><dt>t</dt><dd><blockquote><p>quote # Heading</p></blockquote></dd></dl>',
            ],
            'c016' => [
                '> :: t
> : para
  # H
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H</dd>
</dl>
</blockquote>',
            ],
            'c019' => [
                '> :: t
> : para
    ---
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—</dd>
</dl>
</blockquote>',
            ],
            'c021' => [
                '> :: t
> : para
    [r]: /u
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
[r]: /u
tail</dd>
</dl>
</blockquote>',
            ],
            'c032' => [
                '> :: t
> : para
>   more
>  [r]: /u
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
more
[r]: /u</dd>
</dl>
</blockquote>',
            ],
            'c051' => [
                '> :: t
> : para
    [^p]: n
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
[^p]: n
tail</dd>
</dl>
</blockquote>',
            ],
            'c063' => [
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
            'c067' => [
                '> :: t
> : para
  | a |
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
| a |</dd>
</dl>
</blockquote>',
            ],
            'c068' => [
                '> :: t
> : para
     ---
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—</dd>
</dl>
</blockquote>',
            ],
            'c070' => [
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
            'c073' => [
                '> :: t
> : para
        {#x}
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
{<span class="tag"><strong>#x</strong></span>}
tail</dd>
</dl>
</blockquote>',
            ],
            'c074' => [
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
            'c083' => [
                '> :: t
> : para
        ---
    tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—
tail</dd>
</dl>
</blockquote>',
            ],
            'c086' => [
                '> :: t
> : para
 ---
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—</dd>
</dl>
</blockquote>',
            ],
            'c089' => [
                '> :: t
> : para
        {#x}
    tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
{<span class="tag"><strong>#x</strong></span>}
tail</dd>
</dl>
</blockquote>',
            ],
            'c093' => [
                '> :: t
> : para
  | a |
x
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
| a |
x</dd>
</dl>
</blockquote>',
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
<dt>t
<code></code></dt>
</dl>
</blockquote>',
            ],
            'c100' => [
                '> :: t
> : para
    ---
x
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—
x</dd>
</dl>
</blockquote>',
            ],
            'c102' => [
                '> :: t
> : para
   ---
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—
tail</dd>
</dl>
</blockquote>',
            ],
            'c108' => [
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
            'c109' => [
                '> :: t
> : para
        | a |
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
| a |</dd>
</dl>
</blockquote>',
            ],
            'c112' => [
                '> :: t
> : para
  {#x}
    tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
{<span class="tag"><strong>#x</strong></span>}
tail</dd>
</dl>
</blockquote>',
            ],
            'c113' => [
                '> :: t
> : para
   # H
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H</dd>
</dl>
</blockquote>',
            ],
            'c117' => [
                '> :: t
> : para
  {#x}
x
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
{<span class="tag"><strong>#x</strong></span>}
x</dd>
</dl>
</blockquote>',
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
<dd>para
- li</dd>
</dl>
</blockquote>',
            ],
            'c131' => [
                '> :: t
> : para
  ---
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
—</dd>
</dl>
</blockquote>',
            ],
            'c136' => [
                '> :: t
> : para
 # H
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H</dd>
</dl>
</blockquote>',
            ],
            'c140' => [
                '> :: t
> : para
    # H
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H</dd>
</dl>
</blockquote>',
            ],
            'c147' => [
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
            'c148' => [
                '> :: t
> : para
        [r]: /u
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
[r]: /u
tail</dd>
</dl>
</blockquote>',
            ],
            'c151' => [
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
            'c158' => [
                '> :: t
> : para
  [r]: /u
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
[r]: /u
tail</dd>
</dl>
</blockquote>',
            ],
            'c172' => [
                '> :: t
> : para
        [^p]: n
tail
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
[^p]: n
tail</dd>
</dl>
</blockquote>',
            ],
            'c187' => [
                '> :: t
> : para
  # H
x
', '<blockquote>
<dl>
<dt>t</dt>
<dd>para
# H
x</dd>
</dl>
</blockquote>',
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
