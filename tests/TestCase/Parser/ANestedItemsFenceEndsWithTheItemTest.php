<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A nested item's fence ends with the item, not with the collected body.
 *
 * PART 9 SS10 I4 opens a fence over an open paragraph only when a closer follows
 * at the run's own column; an unterminated opener stays paragraph text, and the
 * stray run in that prose becomes an unclosed inline verbatim run. The outer
 * item's trailing tracker read a flat stream, so a fence written at the NESTED
 * item's content column stayed open for the rest of the body: the payload line
 * that left the nested container did not end it, and the unterminated column-0
 * run below then read as a block start that ended the outer item and published a
 * document-level code block.
 *
 * The fence now records the nested content column it sits at, and a non-blank
 * line below that column ends the fence with the container that held it. A fence
 * merely indented inside the collected body records no such column, so a later
 * line at a lower indent is still its payload.
 *
 * Measured against the oracle (`scripts/spec/layout.mjs` with
 * `scripts/spec/html.mjs`) at markup-carve/carve 9b938e8a, this repo's pin, over
 * the 16 shapes of the two-deep item host: both delimiters, the opener at column
 * 4 or 5, the payload at column 2 or 3 and the run at column 0 or 1. All 16
 * disagreed before and agree after; carve-js already agreed throughout.
 *
 * Carried here rather than in the corpus because the pin predates the category.
 */
class ANestedItemsFenceEndsWithTheItemTest extends TestCase
{
    private function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedFenceShapes(): array
    {
        return [
            'code opener at column 4, payload at column 2, run at column 0' => [
                "- a\n  - b\n\n    ```\n  a\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 4, payload at column 2, run at column 1' => [
                "- a\n  - b\n\n    ```\n  a\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 4, payload at column 3, run at column 0' => [
                "- a\n  - b\n\n    ```\n   a\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 4, payload at column 3, run at column 1' => [
                "- a\n  - b\n\n    ```\n   a\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 5, payload at column 2, run at column 0' => [
                "- a\n  - b\n\n     ```\n  a\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 5, payload at column 2, run at column 1' => [
                "- a\n  - b\n\n     ```\n  a\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 5, payload at column 3, run at column 0' => [
                "- a\n  - b\n\n     ```\n   a\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'code opener at column 5, payload at column 3, run at column 1' => [
                "- a\n  - b\n\n     ```\n   a\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n<code></code>\n  </li>\n</ul>",
            ],
            'tilde opener at column 4, payload at column 2, run at column 0' => [
                "- a\n  - b\n\n    ~~~\n  a\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 4, payload at column 2, run at column 1' => [
                "- a\n  - b\n\n    ~~~\n  a\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 4, payload at column 3, run at column 0' => [
                "- a\n  - b\n\n    ~~~\n   a\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 4, payload at column 3, run at column 1' => [
                "- a\n  - b\n\n    ~~~\n   a\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 5, payload at column 2, run at column 0' => [
                "- a\n  - b\n\n     ~~~\n  a\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 5, payload at column 2, run at column 1' => [
                "- a\n  - b\n\n     ~~~\n  a\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 5, payload at column 3, run at column 0' => [
                "- a\n  - b\n\n     ~~~\n   a\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
            'tilde opener at column 5, payload at column 3, run at column 1' => [
                "- a\n  - b\n\n     ~~~\n   a\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n    a\n~~~\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('nestedFenceShapes')]
    public function testTheRunBelowTheNestedColumnStaysParagraphText(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The control: no nested container, so the fence is the item's own and a
     * later line at a lower indent remains its payload.
     *
     * @return array<string, array{string, string}>
     */
    public static function indentedFenceInTheSameItem(): array
    {
        return [
            'closer at the payload column' => [
                "- a\n\n      ```\n  b\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>",
            ],
            'closer at the fence column' => [
                "- a\n\n      ```\n  b\n      ```\n",
                "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('indentedFenceInTheSameItem')]
    public function testAnIndentedFenceKeepsALowerIndentedPayload(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }
}
