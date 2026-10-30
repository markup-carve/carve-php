<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FENCED BODY IS NOT A PARAGRAPH (`CARVE-P0-013`) in a description body.
 *
 * A line below the body's column has no paragraph to fold into once the body
 * has a fence open, so the body ends there and the line is read at document
 * level. A valid opener opens the fence whether or not a closer follows
 * (markup-carve/carve#4032).
 *
 * Every case here was measured against carve-js before it was written.
 */
class ADescriptionBodysOpenFenceEndsAtABelowColumnLineTest extends TestCase
{
    use RuleATodo;

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
            // The two rows carve-php#2233 names.
            'corpus-478-3' => [
                ":: t\n: a\n  ```\n  b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'corpus-480-7' => [
                ":: t\n: a\n  ```\n  :::\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>:::\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            // A blank and a later paragraph do not change where the body ends.
            'corpus-478-5-negative' => [
                ":: t\n: a\n  ```\n  b\n y\n\nz\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<p>z</p>\n<pre><code></code></pre>",
            ],
            // The spellings the same rule has to reach.
            'tilde-twin' => [
                ":: t\n: a\n  ~~~\n  b\n y\n  ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'no-closer-anywhere' => [
                ":: t\n: a\n  ```\n  b\n y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>",
            ],
            'closer-past-a-new-entry' => [
                ":: t\n: a\n  ```\n  b\n y\n:: t2\n: c\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<dl>\n  <dt>t2</dt>\n  <dd>\n    <p>c</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>",
            ],
            // With no paragraph above it, the fence opens the same way.
            'fence-at-body-start' => [
                ":: t\n: ```\n  b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'longer-closer-run' => [
                ":: t\n: a\n  ```\n  b\n y\n  `````\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            // The list-item host of the same document, which read it this
            // way already. The two collectors have to agree.
            'item-twin' => [
                "- a\n  ```\n  b\n y\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            // An opener past the body's column answers to the column the
            // AUTHOR gave it: a closer written there closes it, one at the
            // body's column does not (raised by codex review).
            'overindented-3' => [
                ":: t\n: a\n   ```\n   b\n y\n   ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\ny\n</code></pre>\n  </dd>\n</dl>",
            ],
            'overindented-4' => [
                ":: t\n: a\n    ```\n    b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\ny\n</code></pre>\n  </dd>\n</dl>",
            ],
            'overindented-closer-at-body-column' => [
                ":: t\n: a\n   ```\n   b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\ny\n</code></pre>\n  </dd>\n</dl>",
            ],
            'closer-deeper-than-opener' => [
                ":: t\n: a\n  ```\n  b\n y\n   ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'opener-deeper-closer-deeper-still' => [
                ":: t\n: a\n   ```\n   b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'tilde-overindented' => [
                ":: t\n: a\n   ~~~\n   b\n y\n   ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\ny\n</code></pre>\n  </dd>\n</dl>",
            ],
            'two-below-column-lines' => [
                ":: t\n: a\n  ```\n  b\n y\n z\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\nz</p>\n<pre><code></code></pre>",
            ],
            'below-column-then-at-column' => [
                ":: t\n: a\n  ```\n  b\n y\n  c\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\nc</p>\n<pre><code></code></pre>",
            ],
            'wide-separator-body' => [
                ":: t\n:   a\n    ```\n    b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'second-entry-after' => [
                ":: t\n: a\n  ```\n  b\n y\n  ```\n:: t2\n: c\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code>:: t2\n: c\n</code></pre>",
            ],
            'blank-inside-fence' => [
                ":: t\n: a\n  ```\n  b\n\n  c\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n\nc\n</code></pre>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'raw-block-opener' => [
                ":: t\n: a\n  ```=html\n  <b>\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <b>\n  </dd>\n</dl>\n<p>y</p>\n<pre><code></code></pre>",
            ],
            'nested-quote-host' => [
                "> :: t\n> : a\n>   ```\n>   b\n>  y\n>   ```\n",
                "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>a</p>\n      <pre><code>b\n</code></pre>\n    </dd>\n  </dl>\n  <p>y</p>\n  <pre><code></code></pre>\n</blockquote>",
            ],
        ];
    }
}
