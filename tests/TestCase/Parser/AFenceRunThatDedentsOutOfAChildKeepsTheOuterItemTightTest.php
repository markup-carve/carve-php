<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFenceRunThatDedentsOutOfAChildKeepsTheOuterItemTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        yield 'ticket' => ["- outer\n  - head\n\n    ```\n    a\n  ```\n\n  tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ul>"];
        yield '-2' => ["- outer\n  - head\n\n    ```\n    a\n   ```\n\n  tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ul>"];
        yield '-3' => ["- outer\n  - head\n\n    ~~~\n    a\n   ~~~\n\n  tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ul>"];
        yield '-4' => ["- outer\n  - head\n\n        ```\n        a\n   ```\n\n  tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ul>"];
        yield '-5' => ["- outer\n  - head\n\n    ```\n    a\n   ```\n\n    tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\n tail\n</code></pre>\n  </li>\n</ul>"];
        yield '-6' => ["- outer\n  - one\n  - two\n\n    ```\n    a\n  ```\n\n  tail\n", "<ul>\n  <li>outer\n    <ul>\n      <li>one</li>\n      <li>two\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ul>"];
        yield '-7' => ["1. outer\n   - head\n\n     ```\n     a\n   ```\n\n   tail\n", "<ol>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n</ol>"];
        yield '-8' => ["> - outer\n>   - head\n>\n>     ```\n>     a\n>   ```\n>\n>   tail\n", "<blockquote>\n  <ul>\n    <li>outer\n      <ul>\n        <li>head\n          <pre><code>a\n</code></pre>\n        </li>\n      </ul>\n      <pre><code>\ntail\n</code></pre>\n    </li>\n  </ul>\n</blockquote>"];
        yield '-9' => ["- outer\n  - head\n\n    ```\n    a\n  ```\n\n  tail\n- next\n", "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\ntail\n</code></pre>\n  </li>\n  <li>next</li>\n</ul>"];
        yield '-10' => ["- outer\n  - head\n\n    ```\n    a\n  ```\n\n- next\n", "<ul>\n  <li><p>outer</p>\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\n</code></pre>\n  </li>\n  <li><p>next</p></li>\n</ul>"];
        yield '-11' => ["- outer\n  - head\n\n    ```\n    a\n    ```\n\n  tail\n", "<ul>\n  <li><p>outer</p>\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n    <p>tail</p>\n  </li>\n</ul>"];
    }

    #[DataProvider('rows')]
    public function testHtmlMatchesCorpus(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);

        $this->assertSame($expected, rtrim($actual, "\n"));
    }
}
