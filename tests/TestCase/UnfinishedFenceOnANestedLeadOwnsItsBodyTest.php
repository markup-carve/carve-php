<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnfinishedFenceOnANestedLeadOwnsItsBodyTest extends TestCase
{
    #[DataProvider('ownedProvider')]
    public function testTheFenceOwnsWhatTheContainerFoldedIn(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ownedProvider(): array
    {
        return [
            'flush-left body and closer' => ["- - ``` x\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'body indented one below column' => ["- - ``` x\n code\n ```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'three levels deep' => ["- - - ``` x\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code class=\"language-x\"></code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'a quoted fence needs marked body lines' => ["> - ``` x\ncode\n```\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-x\"></code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>code\n<code></code></p>"],
            'ordered markers' => ["1. 1. ``` x\ncode\n```\n", "<ol>\n  <li>\n    <ol>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ol>\n  </li>\n</ol>\n<p>code\n<code></code></p>"],
            'tilde fence' => ["- - ~~~ x\ncode\n~~~\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n~~~</p>"],
            'no info string' => ["- - ```\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'no closer at all' => ["- - ``` x\ncode\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code</p>"],
            'plain follower below nested content column' => ["- - ```\ntail\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>"],
            'outer paragraph after a three-level fence takes a lazy line' => ["- - - ```\n  tail\ntail\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n    tail\ntail\n  </li>\n</ul>"],
            'outer fence after nested fence takes no lazy line' => ["- - ```\n  ```\ntail\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>tail</p>"],
            'quoted outer paragraph takes a lazy line' => ["> - - - ```\n>   tail\n> tail\n", "<blockquote>\n  <ul>\n    <li>\n      <ul>\n        <li>\n          <ul>\n            <li>\n              <pre><code></code></pre>\n            </li>\n          </ul>\n        </li>\n      </ul>\n      tail\ntail\n    </li>\n  </ul>\n</blockquote>"],
            'quote padding keeps the outer paragraph claim' => [">  - - - ```\n>    tail\n>  tail\n", "<blockquote>\n  <ul>\n    <li>\n      <ul>\n        <li>\n          <ul>\n            <li>\n              <pre><code></code></pre>\n            </li>\n          </ul>\n        </li>\n      </ul>\n      tail\ntail\n    </li>\n  </ul>\n</blockquote>"],
            'closer is only line below' => ["- - ``` x\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code></code></pre>"],
            'trailing lazy item' => ["- - ``` x\ncode\n```\n- lazy\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code>\n- lazy</code></p>"],
            'a closer with body below it' => ["- - ``` x\na\n```\nb\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>a\n<code>\nb</code></p>"],
            'two closing runs' => ["- - ``` x\na\n```\nb\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>a</p>\n<pre><code>b\n</code></pre>"],
        ];
    }

    #[DataProvider('unchangedProvider')]
    public function testTheNeighbouringShapesDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'outermost depth 1 (control)' => ["- ``` x\ncode\n```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\"></code></pre>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'body at content column (control)' => ["- - ``` x\n    code\n    ```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'blank line above body (control)' => ["- - ``` x\n\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'colon container on lead (control)' => [
                "- - ::: d\nbody\n:::\n", '<ul>
  <li>
    <ul>
      <li>
        <div class="d">

        </div>
      </li>
    </ul>
  </li>
</ul>
<p>body</p>
<div>

</div>',
            ],
        ];
    }

    /**
     * The frame that carries "an enclosing container folded this line in" is a
     * NUL run, and a NUL in the source is replaced with U+FFFD before the first
     * line is read - so no document can spell one, and none reaches the output.
     */
    public function testTheFrameIsNeitherForgeableNorPublished(): void
    {
        $converter = new CarveConverter();
        $this->assertStringNotContainsString("\x00", $converter->convert("- - ``` x\ncode\n```\n"));
        $this->assertSame("<p>a\u{FFFD}L\u{FFFD}b</p>\n", $converter->convert("a\x00L\x00b\n"));
    }
}
