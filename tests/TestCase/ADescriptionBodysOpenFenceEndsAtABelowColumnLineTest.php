<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FENCED BODY IS NOT A PARAGRAPH (`CARVE-P0-013`) in a description body.
 *
 * A line below the body's column has no paragraph to fold into once the body
 * has a fence open, so the body ends there and the line is read at document
 * level. Whether the fence opened is §10 I4's question, and `CARVE-P0-014`
 * does not stop that search at the below-column line - which is what
 * carve-php#2233 was: the collector asked for the closer in the lines it had
 * COLLECTED, a view that stops at the line being classified, so a closer
 * written under the below-column line was invisible.
 *
 * Every case here was measured against carve-js before it was written.
 */
class ADescriptionBodysOpenFenceEndsAtABelowColumnLineTest extends TestCase
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
            // The two rows carve-php#2233 names.
            'corpus-478-3' => [
                ":: t\n: a\n  ```\n  b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'corpus-480-7' => [
                ":: t\n: a\n  ```\n  :::\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>:::\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            // The negatives. The closer search is bounded where the body
            // really ends, so a closer past that boundary arms nothing and
            // the below-column line folds into the paragraph as section 24
            // S4 has it.
            'corpus-478-5-negative' => [
                ":: t\n: a\n  ```\n  b\n y\n\nz\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny</code></dd>\n</dl>\n<p>z\n<code></code></p>",
            ],
            // The spellings the same rule has to reach.
            'tilde-twin' => [
                ":: t\n: a\n  ~~~\n  b\n y\n  ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n~~~</p>",
            ],
            'no-closer-anywhere' => [
                ":: t\n: a\n  ```\n  b\n y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny</code></dd>\n</dl>",
            ],
            'closer-past-a-new-entry' => [
                ":: t\n: a\n  ```\n  b\n y\n:: t2\n: c\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny</code></dd>\n  <dt>t2</dt>\n  <dd>c\n<code></code></dd>\n</dl>",
            ],
            // Already correct before the fix, and the reason it is a
            // lookahead rather than a new opener rule: with no paragraph
            // above it, the fence opens whether or not a closer follows.
            'fence-at-body-start' => [
                ":: t\n: ```\n  b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'longer-closer-run' => [
                ":: t\n: a\n  ```\n  b\n y\n  `````\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            // The list-item host of the same document, which read it this
            // way already. The two collectors have to agree.
            'item-twin' => [
                "- a\n  ```\n  b\n y\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>\n<p>y\n<code></code></p>",
            ],
            // An opener past the body's column answers to the column the
            // AUTHOR gave it: a closer written there closes it, one at the
            // body's column does not (raised by codex review).
            'overindented-3' => [
                ":: t\n: a\n   ```\n   b\n y\n   ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'overindented-4' => [
                ":: t\n: a\n    ```\n    b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'overindented-closer-at-body-column' => [
                ":: t\n: a\n   ```\n   b\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny\n</code></dd>\n</dl>",
            ],
            'closer-deeper-than-opener' => [
                ":: t\n: a\n  ```\n  b\n y\n   ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny\n</code></dd>\n</dl>",
            ],
            'opener-deeper-closer-deeper-still' => [
                ":: t\n: a\n   ```\n   b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny\n</code></dd>\n</dl>",
            ],
            'tilde-overindented' => [
                ":: t\n: a\n   ~~~\n   b\n y\n   ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n~~~</p>",
            ],
            'two-below-column-lines' => [
                ":: t\n: a\n  ```\n  b\n y\n z\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\nz\n<code></code></p>",
            ],
            'below-column-then-at-column' => [
                ":: t\n: a\n  ```\n  b\n y\n  c\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\nc\n<code></code></p>",
            ],
            'wide-separator-body' => [
                ":: t\n:   a\n    ```\n    b\n y\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'second-entry-after' => [
                ":: t\n: a\n  ```\n  b\n y\n  ```\n:: t2\n: c\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>\n<dl>\n  <dt>t2</dt>\n  <dd>c</dd>\n</dl>",
            ],
            'blank-inside-fence' => [
                ":: t\n: a\n  ```\n  b\n\n  c\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code>b\n\nc\n</code></pre>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'raw-block-opener' => [
                ":: t\n: a\n  ```=html\n  <b>\n y\n  ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <b>\n  </dd>\n</dl>\n<p>y\n<code></code></p>",
            ],
            'nested-quote-host' => [
                "> :: t\n> : a\n>   ```\n>   b\n>  y\n>   ```\n",
                "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>a</p>\n      <pre><code>b\n</code></pre>\n    </dd>\n  </dl>\n  <p>y\n<code></code></p>\n</blockquote>",
            ],
        ];
    }
}
