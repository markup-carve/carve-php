<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1900, ruled on markup-carve/carve#1900.
 *
 * A fence at an item's block start runs to the END OF ITS CONTAINER, so an
 * unfinished fence opened on a NESTED item's marker lead owns the lines the
 * enclosing container folded in below its column - and a closing run written
 * among them is body text, because a fence's content is not re-scanned for
 * structure.
 *
 * This engine published an EMPTY `pre`, leaked the body into the outer item as
 * prose and returned the closer as a stray inline `code`. Every expectation
 * here is the executable spec's answer, read from `scripts/spec/layout.mjs`
 * into `scripts/spec/html.mjs`; the pinned spec 95fc3a04 and spec main 063656e7
 * agree on every one of them.
 *
 * The OUTERMOST spelling is the control and must NOT move: with no container
 * above it nothing was folded in, and the spec leaks the body to the document
 * exactly as this engine already did.
 */
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
            'flush-left body and closer' => ["- - ``` x\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'body indented one below column' => ["- - ``` x\n code\n ```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'three levels deep' => ["- - - ``` x\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code class=\"language-x\">code\n```\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'quote as host' => ["> - ``` x\ncode\n```\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-x\">code\n```\n</code></pre>\n    </li>\n  </ul>\n</blockquote>"],
            'ordered markers' => ["1. 1. ``` x\ncode\n```\n", "<ol>\n  <li>\n    <ol>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ol>\n  </li>\n</ol>"],
            'tilde fence' => ["- - ~~~ x\ncode\n~~~\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n~~~\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'no info string' => ["- - ```\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'no closer at all' => ["- - ``` x\ncode\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'closer is only line below' => ["- - ``` x\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'trailing lazy item' => ["- - ``` x\ncode\n```\n- lazy\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n  <li>lazy</li>\n</ul>"],
            'a closer with body below it' => ["- - ``` x\na\n```\nb\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">a\n```\nb\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'two closing runs' => ["- - ``` x\na\n```\nb\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">a\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code>b\n</code></pre>"],
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
            'outermost depth 1 (control)' => ["- ``` x\ncode\n```\n", "<ul>\n  <li>\n    <pre><code class=\"language-x\">\n</code></pre>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'body at content column (control)' => ["- - ``` x\n    code\n    ```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'blank line above body (control)' => ["- - ``` x\n\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>code\n<code></code></p>"],
            'colon container on lead (control)' => ["- - ::: d\nbody\n:::\n", "<ul>\n  <li>\n    <ul>\n      <li>::: d\nbody</li>\n    </ul>\n  </li>\n</ul>\n<div>\n</div>"],
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
