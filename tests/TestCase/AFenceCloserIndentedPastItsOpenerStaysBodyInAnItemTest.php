<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFenceCloserIndentedPastItsOpenerStaysBodyInAnItemTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fenceProvider(): array
    {
        return [
            'backtick item +0 closes' => ["- ``` x\n  code\n  ```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n</code></pre>\n  </li>\n</ul>"],
            'backtick item +1 stays body' => ["- ``` x\n  code\n   ```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n ```\n</code></pre>\n  </li>\n</ul>"],
            'backtick item +2 stays body' => ["- ``` x\n  code\n    ```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n  ```\n</code></pre>\n  </li>\n</ul>"],
            'backtick item +3 stays body' => ["- ``` x\n  code\n     ```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n   ```\n</code></pre>\n  </li>\n</ul>"],
            'backtick top-level control' => ["``` x\n   code\n   ```\n", "<pre><code class=\"language-x\">   code\n   ```\n</code></pre>"],
            'tilde item +0 closes' => ["- ~~~ x\n  code\n  ~~~\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n</code></pre>\n  </li>\n</ul>"],
            'tilde item +1 stays body' => ["- ~~~ x\n  code\n   ~~~\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n ~~~\n</code></pre>\n  </li>\n</ul>"],
            'tilde item +2 stays body' => ["- ~~~ x\n  code\n    ~~~\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n  ~~~\n</code></pre>\n  </li>\n</ul>"],
            'tilde item +3 stays body' => ["- ~~~ x\n  code\n     ~~~\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">code\n   ~~~\n</code></pre>\n  </li>\n</ul>"],
            'tilde top-level control' => ["~~~ x\n   code\n   ~~~\n", "<pre><code class=\"language-x\">   code\n   ~~~\n</code></pre>"],
            'info-less item +0 closes' => ["- ```\n  code\n  ```\n", "<ul>\n  <li>\n    <pre><code>code\n</code></pre>\n  </li>\n</ul>"],
            'info-less item +1 stays body' => ["- ```\n  code\n   ```\n", "<ul>\n  <li>\n    <pre><code>code\n ```\n</code></pre>\n  </li>\n</ul>"],
            'info-less item +2 stays body' => ["- ```\n  code\n    ```\n", "<ul>\n  <li>\n    <pre><code>code\n  ```\n</code></pre>\n  </li>\n</ul>"],
            'info-less item +3 stays body' => ["- ```\n  code\n     ```\n", "<ul>\n  <li>\n    <pre><code>code\n   ```\n</code></pre>\n  </li>\n</ul>"],
            'info-less top-level control' => ["```\n   code\n   ```\n", "<pre><code>   code\n   ```\n</code></pre>"],
        ];
    }

    #[DataProvider('fenceProvider')]
    public function testFenceCloserIndentation(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }
}
