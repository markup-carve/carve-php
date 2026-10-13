<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description body's nested lead closes its fence at the lead's own content
 * column, so the flush-left line below the body is nobody's verbatim content
 * and PART 9 §10's closer lookahead answers it: with a closer ahead the line
 * interrupts and the document takes the fence (carve-php#2878). The closed-block boundary applies even without a later closer
 * (markup-carve/carve#2895).
 */
class AClosedNestedLeadFenceDoesNotClaimAFlushLeftFenceLineTest extends TestCase
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
            'ticket' => [":: t\n: - ```\n    ```\n```\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'closer-ahead-with-payload' => [":: t\n: - ```\n    ```\n```\ny\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code>y\n</code></pre>"],
            'closer-ahead-tilde' => [":: t\n: - ~~~\n    ~~~\n~~~\n~~~\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'closer-ahead-depth-two' => [":: t\n: - - ```\n      ```\n```\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'closer-ahead-info-string' => [":: t\n: - ```\n    ```\n``` js\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code class=\"language-js\"></code></pre>"],
            'no-closer-ahead' => [":: t\n: - ```\n    ```\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'ctrl-nested-lead-fence-open' => [":: t\n: - ```\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'ctrl-nested-lead-fence-open-closer-ahead' => [":: t\n: - ```\n```\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>"],
            'ctrl-entry-below-survives' => [":: t\n: - ```\n    ```\n```\n```\n\n:: u\n: v\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code></code></pre>\n<dl>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>"],
            'ctrl-closed-then-flush-left' => [":: t\n: - ```\n    x\n    ```\n```\n\n:: u\n: v\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<pre><code>\n:: u\n: v\n</code></pre>"],
        ];
    }
}
