<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownEmptyItemFixedPointTest extends TestCase
{
    public function testEmptyItemFolding(): void
    {
        $rows = [
            'cm-278' => [
                '-
  foo
-
  ```
  bar
  ```
-
      baz
', '- foo
- ```
  bar
  ```
- ```
  baz
  ```
', '<ul>
  <li>foo</li>
  <li>
    <pre><code>bar
</code></pre>
  </li>
  <li>
    <pre><code>baz
</code></pre>
  </li>
</ul>
',
            ],
            'cm-279' => [
                '-   
  foo
', '- foo
', '<ul>
  <li>foo</li>
</ul>
',
            ],
        ];

        foreach ($rows as $id => [$source, $expected, $html]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame(preg_replace('/\n\z/', '', $html), (new CarveConverter())->convert($actual), $id);
        }
    }
}
