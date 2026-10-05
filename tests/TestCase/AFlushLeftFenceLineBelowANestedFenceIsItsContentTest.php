<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A flush-left fence line cannot close a fence a description body's nested
 * lead left open (markup-carve/carve#1958, corpus 455), so it is that fence's
 * verbatim content and the entry below it survives as its own term.
 */
class AFlushLeftFenceLineBelowANestedFenceIsItsContentTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testRequiredOutput(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseProvider(): array
    {
        return [
            'ticket' => [":: t\n: - ```\n```\n\n:: t\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code>```\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n  <dt>t</dt>\n</dl>\n<pre><code></code></pre>"],
            'depth-two-lead' => [":: t\n: - - ```\n```\n\n:: t\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code>```\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n  <dt>t</dt>\n</dl>\n<pre><code></code></pre>"],
            'term-tilde-fence' => [":: t\n~~~\n", "<dl>\n  <dt>t</dt>\n</dl>\n<pre><code></code></pre>"],
            'ctrl-closer-at-content-column' => [":: t\n: - ```\n    ```\n\n:: t\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n  <dt>t</dt>\n</dl>"],
            'ctrl-closed-then-flush-left' => [":: t\n: - ```\n    x\n    ```\n```\n\n:: u\n: v\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n    <p><code></code></p>\n  </dd>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>"],
            'ctrl-fence-owns-its-content' => [":: t\n: - ```\n  a\n```\n\n:: u\n: v\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>a\n<code></code></p>\n  </dd>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>"],
            'ctrl-term-indented-fence' => [":: t\n   ```\n", "<dl>\n  <dt>t\n   <code></code></dt>\n</dl>"],
            'ctrl-term-fence-closed-ahead' => [":: t\n```\nx\n```\n", "<dl>\n  <dt>t</dt>\n</dl>\n<pre><code>x\n</code></pre>"],
            'ctrl-plain-description-body' => [":: t\n: ```\n```\n\n:: u\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<pre><code>\n:: u\n</code></pre>"],
            'ctrl-list-item-host' => ["- ```\n```\n\nx\n", "<ul>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<pre><code>\nx\n</code></pre>"],
            'ctrl-line-block-lead' => [":: t\n: - |\n| x\n\n:: u\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>|\n| x</li>\n    </ul>\n  </dd>\n  <dt>u</dt>\n</dl>"],
        ];
    }
}
