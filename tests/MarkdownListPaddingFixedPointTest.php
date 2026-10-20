<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownListPaddingFixedPointTest extends TestCase
{
    public function testListPaddingSpellings(): void
    {
        $rows = [
            'cm-109' => [
                '1.  foo

    - bar
', '{loose}
1. foo

   - bar
', '<ol>
  <li><p>foo</p>
    <ul>
      <li>bar</li>
    </ul>
  </li>
</ol>
',
            ],
            'cm-254' => [
                '1.  A paragraph
    with two lines.

        indented code

    > A block quote.
', '{loose}
1. A paragraph
   with two lines.

   ```
   indented code
   ```

   > A block quote.
', '<ol>
  <li><p>A paragraph
with two lines.</p>
    <pre><code>indented code
</code></pre>
    <blockquote><p>A block quote.</p></blockquote>
  </li>
</ol>
',
            ],
            'cm-257' => [
                ' -    one

     two
', '- one

```
 two
```
', '<ul>
  <li>one</li>
</ul>
<pre><code> two
</code></pre>
',
            ],
            'cm-259' => [
                '   > > 1.  one
>>
>>     two
', '> > 1. one
> >
> >    two
', '<blockquote>
  <blockquote>
    <ol>
      <li><p>one</p>
        <p>two</p>
      </li>
    </ol>
  </blockquote>
</blockquote>
',
            ],
            'cm-271' => [
                '  10.  foo

           bar
', '{loose}
10. foo

    ```
    bar
    ```
', '<ol start="10">
  <li><p>foo</p>
    <pre><code>bar
</code></pre>
  </li>
</ol>
',
            ],
            'cm-276' => [
                '-    foo

  bar
', '- foo

bar
', '<ul>
  <li>foo</li>
</ul>
<p>bar</p>
',
            ],
            'cm-286' => [
                ' 1.  A paragraph
     with two lines.

         indented code

     > A block quote.
', '{loose}
1. A paragraph
   with two lines.

   ```
   indented code
   ```

   > A block quote.
', '<ol>
  <li><p>A paragraph
with two lines.</p>
    <pre><code>indented code
</code></pre>
    <blockquote><p>A block quote.</p></blockquote>
  </li>
</ol>
',
            ],
            'cm-287' => [
                '  1.  A paragraph
      with two lines.

          indented code

      > A block quote.
', '{loose}
1. A paragraph
   with two lines.

   ```
   indented code
   ```

   > A block quote.
', '<ol>
  <li><p>A paragraph
with two lines.</p>
    <pre><code>indented code
</code></pre>
    <blockquote><p>A block quote.</p></blockquote>
  </li>
</ol>
',
            ],
            'cm-288' => [
                '   1.  A paragraph
       with two lines.

           indented code

       > A block quote.
', '{loose}
1. A paragraph
   with two lines.

   ```
   indented code
   ```

   > A block quote.
', '<ol>
  <li><p>A paragraph
with two lines.</p>
    <pre><code>indented code
</code></pre>
    <blockquote><p>A block quote.</p></blockquote>
  </li>
</ol>
',
            ],
            'cm-290' => [
                '  1.  A paragraph
with two lines.

          indented code

      > A block quote.
', '{loose}
1. A paragraph
   with two lines.

   ```
   indented code
   ```

   > A block quote.
', '<ol>
  <li><p>A paragraph
with two lines.</p>
    <pre><code>indented code
</code></pre>
    <blockquote><p>A block quote.</p></blockquote>
  </li>
</ol>
',
            ],
            'cm-291' => [
                '  1.  A paragraph
    with two lines.
', '1. A paragraph
   with two lines.
', '<ol>
  <li>A paragraph
with two lines.</li>
</ol>
',
            ],
            'cm-309' => [
                '-   foo

    notcode

-   foo

<!-- -->

    code
', '- foo

  notcode

- foo

```=html
<!-- -->
```

```
code
```
', '<ul>
  <li><p>foo</p>
    <p>notcode</p>
  </li>
  <li><p>foo</p></li>
</ul>
<!-- -->
<pre><code>code
</code></pre>
',
            ],
        ];

        foreach ($rows as $id => [$source, $expected, $html]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($html, (new CarveConverter())->convert($actual), $id);
        }
    }
}
