<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence run below its fence's base ends containers down to its owner.
 *
 * `CARVE-P0-013` is stated one container deep and its owner table carries no
 * depth term, so the nested item host answers to it at any depth
 * (markup-carve/carve#2490, ruled on carve-php#2610 family 1 and implemented in
 * the oracle by markup-carve/carve#2509, reported as carve-php#2654). A run left
 * of the fence's base is not a closer: with an open paragraph anywhere in the
 * stack it folds there and nothing ends, and with none `CARVE-P0-004`'s table
 * picks the nearest surviving ancestor whose content column the run reaches.
 * `%%%` is excluded by name.
 *
 * DEPTH IS THE PARAMETER THAT BROKE. The collector that owns a single item reads
 * the source, so both halves answered correctly there. A nested item's stream
 * stops at the line that ended it, and two things went missing with it: a fence
 * armed without asking §10 I4 reported a closed block the deepest structure did
 * not hold, so the below-base run ended the list instead of folding; and a fence
 * that did interrupt lost the closer the break removed, so the item's own parse
 * read an opener with none and returned the body as an inline code span.
 *
 * THE BAND MATTERS, not one spelling. Measured against the oracle
 * (`scripts/spec/layout.mjs` with `scripts/spec/html.mjs`) at
 * markup-carve/carve 1f51a01d over 467 shapes across the nested item, colon,
 * quote, description and note hosts, five delimiter kinds, three depths and every
 * run column: 101 disagreed before, 13 after, and all 13 disagreed before this
 * change too. Every row below spells its depth,
 * delimiter and run column, because a host that looks correct is often correct
 * at some columns only.
 *
 * Carried here rather than in the corpus because this repo's spec pin predates
 * the category (carve-php#2655 did the same).
 */
class ABelowBaseFenceRunEndsContainersDownToItsOwnerTest extends TestCase
{
    private function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * The half where a container still holds an open paragraph.
     *
     * No blank above the fence, and no closer at the fence's own column, so
     * §10 I4 leaves the fence as prose. Every line folds into the paragraph the
     * item holds, including the run below the base, and nothing ends.
     *
     * @return array<string, array{string, string}>
     */
    public static function openParagraphHalf(): array
    {
        return [
            'code at depth 2, run at column 0' => [
                "- a\n  - b\n    ```\n    p\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 2, run at column 1' => [
                "- a\n  - b\n    ```\n    p\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 2, run at column 2' => [
                "- a\n  - b\n    ```\n    p\n  ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 2, run at column 3' => [
                "- a\n  - b\n    ```\n    p\n   ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 2, run at column 0' => [
                "- a\n  - b\n    ~~~\n    p\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n~~~\np\n~~~</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 2, run at column 1' => [
                "- a\n  - b\n    ~~~\n    p\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n~~~\np\n~~~</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 2, run at column 2' => [
                "- a\n  - b\n    ~~~\n    p\n  ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n~~~\np\n~~~</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 2, run at column 3' => [
                "- a\n  - b\n    ~~~\n    p\n   ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n~~~\np\n~~~</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 2, run at column 0' => [
                "- a\n  - b\n    ```=html\n    p\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>=html\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 2, run at column 1' => [
                "- a\n  - b\n    ```=html\n    p\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>=html\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 2, run at column 2' => [
                "- a\n  - b\n    ```=html\n    p\n  ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>=html\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 2, run at column 3' => [
                "- a\n  - b\n    ```=html\n    p\n   ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>=html\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 0' => [
                "- a\n  - b\n    - c\n      ```\n      p\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 1' => [
                "- a\n  - b\n    - c\n      ```\n      p\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 2' => [
                "- a\n  - b\n    - c\n      ```\n      p\n  ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 3' => [
                "- a\n  - b\n    - c\n      ```\n      p\n   ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 4' => [
                "- a\n  - b\n    - c\n      ```\n      p\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'code at depth 3, run at column 5' => [
                "- a\n  - b\n    - c\n      ```\n      p\n     ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 0' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 1' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 2' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n  ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 3' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n   ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 4' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n    ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'tilde at depth 3, run at column 5' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n     ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n~~~\np\n~~~</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 0' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 1' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 2' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n  ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 3' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n   ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 4' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'raw at depth 3, run at column 5' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\n     ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>=html\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('openParagraphHalf')]
    public function testABelowBaseRunFoldsIntoTheOpenParagraph(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * The half where the fence did interrupt, and its body has to survive.
     *
     * A closer does sit at the fence's own column, so §10 I4 opens the fence and
     * the paragraph ends. The flush-left line below then finds nothing open, the
     * owner table sends it to the document, and the fence is left unterminated -
     * but the body it opened is still a code block, exactly as it is one level
     * up. The closer the container's break removes from the item's own lines is
     * handed down rather than re-derived (markup-carve/carve#1399 one level in).
     *
     * @return array<string, array{string, string}>
     */
    public static function interruptedFence(): array
    {
        return [
            'code at depth 2' => [
                "- a\n  - b\n    ```\n    p\nx\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
            'tilde at depth 2' => [
                "- a\n  - b\n    ~~~\n    p\nx\n    ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n~~~</p>",
            ],
            'raw at depth 2' => [
                "- a\n  - b\n    ```=html\n    p\nx\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        p\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
            'code at depth 3' => [
                "- a\n  - b\n    - c\n      ```\n      p\nx\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
            'tilde at depth 3' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\nx\n      ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n~~~</p>",
            ],
            'raw at depth 3' => [
                "- a\n  - b\n    - c\n      ```=html\n      p\nx\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            p\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
        ];
    }

    #[DataProvider('interruptedFence')]
    public function testAnInterruptedFenceKeepsItsBody(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: the half with no open paragraph, which this engine already had.
     *
     * A blank above the fence closes the paragraph, so the fence opens whatever
     * follows it and the run below the base selects its owner from
     * `CARVE-P0-004`'s table. Nothing here moves.
     *
     * @return array<string, array{string, string}>
     */
    public static function theBlankHalf(): array
    {
        return [
            'depth 2, run at column 0' => [
                "- a\n  - b\n\n    ```\n    p\n```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code>\n    tail\n</code></pre>",
            ],
            'depth 2, run at column 1' => [
                "- a\n  - b\n\n    ```\n    p\n ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p><code></code></p>\n<p>tail</p>",
            ],
            'depth 2, run at column 2' => [
                "- a\n  - b\n\n    ```\n    p\n  ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\n  tail\n</code></pre>\n  </li>\n</ul>",
            ],
            'depth 2, run at column 3' => [
                "- a\n  - b\n\n    ```\n    p\n   ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n</code></pre>\n      </li>\n    </ul>\n    <pre><code>\n tail\n</code></pre>\n  </li>\n</ul>",
            ],
            'depth 3, run at column 0' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code>\n      tail\n</code></pre>",
            ],
            'depth 3, run at column 1' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n ```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p><code></code></p>\n<p>tail</p>",
            ],
            'depth 3, run at column 2' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n  ```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n    <pre><code>\n    tail\n</code></pre>\n  </li>\n</ul>",
            ],
            'depth 3, run at column 3' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n   ```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n    <pre><code>\n   tail\n</code></pre>\n  </li>\n</ul>",
            ],
            'depth 3, run at column 4' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n    ```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n        <pre><code>\n  tail\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'depth 3, run at column 5' => [
                "- a\n  - b\n    - c\n\n      ```\n      p\n     ```\n\n      tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n</code></pre>\n          </li>\n        </ul>\n        <pre><code>\n tail\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('theBlankHalf')]
    public function testTheOwnerTableIsUnchangedWithNoParagraphOpen(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: the one-container spelling every reader already agreed on.
     *
     * The collector that owns a single item can see the source, so it settled
     * both halves correctly before this change. Its answers are the ones the
     * nested spellings above have to match.
     *
     * @return array<string, array{string, string}>
     */
    public static function theOutermostSpelling(): array
    {
        return [
            'code, no closer' => [
                "- a\n  ```\n  p\n```\n",
                "<ul>\n  <li>a\n<code>\np\n</code></li>\n</ul>",
            ],
            'code, interrupted' => [
                "- a\n  ```\n  p\nx\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>p\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
            'tilde, no closer' => [
                "- a\n  ~~~\n  p\n~~~\n",
                "<ul>\n  <li>a\n~~~\np\n~~~</li>\n</ul>",
            ],
            'tilde, interrupted' => [
                "- a\n  ~~~\n  p\nx\n  ~~~\n",
                "<ul>\n  <li>a\n    <pre><code>p\n</code></pre>\n  </li>\n</ul>\n<p>x\n~~~</p>",
            ],
            'raw, no closer' => [
                "- a\n  ```=html\n  p\n```\n",
                "<ul>\n  <li>a\n<code>=html\np\n</code></li>\n</ul>",
            ],
            'raw, interrupted' => [
                "- a\n  ```=html\n  p\nx\n  ```\n",
                "<ul>\n  <li>a\n    p\n  </li>\n</ul>\n<p>x\n<code></code></p>",
            ],
        ];
    }

    #[DataProvider('theOutermostSpelling')]
    public function testTheOneContainerSpellingIsUnchanged(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: a marker below the fence's column ends the item holding it.
     *
     * The closer written under such a marker belongs to the next SIBLING, so the
     * fence has none and stays prose. Unbounded, the lookahead accepted it and
     * opened a fence in an item whose own lines hold no closer at all.
     *
     * @return array<string, array{string, string}>
     */
    public static function aSiblingMarkerCutsTheLookahead(): array
    {
        return [
            'depth 2, marker at column 0' => [
                "- a\n  - b\n    ```\n    p\n- z\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np</code></li>\n    </ul>\n  </li>\n  <li>z\n<code></code></li>\n</ul>",
            ],
            'depth 2, marker at column 2' => [
                "- a\n  - b\n    ```\n    p\n  - z\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np</code></li>\n      <li>z\n<code></code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'depth 3, marker at column 0' => [
                "- a\n  - b\n    - c\n      ```\n      p\n- z\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n  <li>z\n<code></code></li>\n</ul>",
            ],
            'depth 3, marker at column 4' => [
                "- a\n  - b\n    - c\n      ```\n      p\n    - z\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np</code></li>\n          <li>z\n<code></code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('aSiblingMarkerCutsTheLookahead')]
    public function testASiblingsCloserDoesNotOpenThisFence(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: a run indented past the fence's column is body text.
     *
     * The closer has to be written at the fence's own column. One deeper does
     * not close it, so the fence still has none.
     *
     * @return array<string, array{string, string}>
     */
    public static function aCloserPastTheColumnIsBodyText(): array
    {
        return [
            'depth 2' => [
                "- a\n  - b\n    ```\n    p\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\np\n</code></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'depth 3' => [
                "- a\n  - b\n    - c\n      ```\n      p\n        ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n<code>\np\n</code></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('aCloserPastTheColumnIsBodyText')]
    public function testACloserPastTheFencesColumnIsBodyText(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: the colon host, which `CARVE-P0-014` answers the other way.
     *
     * A colon body can hold an open paragraph, so an indented fence inside one is
     * that container's question and the run below the base folds with it. A fix
     * for the item host must not reach here.
     *
     * @return array<string, array{string, string}>
     */
    public static function theColonHostAnswersTheOtherWay(): array
    {
        return [
            'blank above the fence 0' => [
                ":::\n  ```\n  p\n```\n:::\n",
                "<div>\n  <p><code>\np\n</code></p>\n</div>",
            ],
            'blank above the fence 1' => [
                ":::\n\n  ```\n  p\n```\n:::\n",
                "<div>\n  <p><code>\np\n</code></p>\n</div>",
            ],
        ];
    }

    #[DataProvider('theColonHostAnswersTheOtherWay')]
    public function testAColonBodyKeepsTheRunInside(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: `%%%` is excluded from the ruling by name.
     *
     * Section 28 pairs the two delimiters and indentation is part of neither, so
     * a closer below the base still closes the span and the payload stays hidden.
     *
     * @return array<string, array{string, string}>
     */
    public static function theCommentSpanIsExcludedByName(): array
    {
        return [
            'depth 2' => [
                "- a\n  - b\n    %%%\n    hidden\n%%%\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'depth 3' => [
                "- a\n  - b\n    - c\n      %%%\n      hidden\n%%%\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('theCommentSpanIsExcludedByName')]
    public function testACommentSpanClosesBelowTheBase(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * A closer past a blank line no later line continues, at all three depths.
     *
     * The blank ends the item (carve#1379), so a closer written under it is the
     * document's and §10 I4 has nothing to arm the fence on. At ONE container
     * deep the run therefore folds into the paragraph the item still holds; two
     * and three deep an ancestor collector has already answered I4 for the line
     * and the fence opens, which is the asymmetry carve-php#2661 reports and the
     * oracle keeps.
     *
     * @return array<string, array{string, string}>
     */
    public static function aCloserPastAnUncontinuedBlank(): array
    {
        return [
            'code at depth 1 folds' => [
                "- a\n  ```\n  p\n\ndone\n  ```\n",
                "<ul>\n  <li>a\n<code>\np</code></li>\n</ul>\n<p>done\n<code></code></p>",
            ],
            'tilde at depth 1 folds' => [
                "- a\n  ~~~\n  p\n\ndone\n  ~~~\n",
                "<ul>\n  <li>a\n~~~\np</li>\n</ul>\n<p>done\n~~~</p>",
            ],
            'code at depth 1, fence past the content column, folds' => [
                "- a\n   ```\n   p\n\ndone\n   ```\n",
                "<ul>\n  <li>a\n<code>\np</code></li>\n</ul>\n<p>done\n<code></code></p>",
            ],
            'code at depth 2 opens' => [
                "- a\n  - b\n    ```\n    p\n\ndone\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>done\n<code></code></p>",
            ],
            'tilde at depth 2 opens' => [
                "- a\n  - b\n    ~~~\n    p\n\ndone\n    ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>p\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>done\n~~~</p>",
            ],
            'code at depth 3 opens' => [
                "- a\n  - b\n    - c\n      ```\n      p\n\ndone\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>done\n<code></code></p>",
            ],
            'tilde at depth 3 opens' => [
                "- a\n  - b\n    - c\n      ~~~\n      p\n\ndone\n      ~~~\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\n            <pre><code>p\n\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>done\n~~~</p>",
            ],
        ];
    }

    #[DataProvider('aCloserPastAnUncontinuedBlank')]
    public function testACloserPastAnUncontinuedBlankIsNotThisFences(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL: the blank IS continued, so the closer is the fence's own.
     *
     * One line moves, from column 0 to the item's content column, and the depth-1
     * reading above turns back into a code block that holds the blank.
     *
     * @return array<string, array{string, string}>
     */
    public static function aContinuedBlankLeavesTheCloserReachable(): array
    {
        return [
            'code at depth 1' => [
                "- a\n  ```\n  p\n\n  done\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>p\n\ndone\n</code></pre>\n  </li>\n</ul>",
            ],
            'tilde at depth 1' => [
                "- a\n  ~~~\n  p\n\n  done\n  ~~~\n",
                "<ul>\n  <li>a\n    <pre><code>p\n\ndone\n</code></pre>\n  </li>\n</ul>",
            ],
            'code at depth 1, no blank at all' => [
                "- a\n  ```\n  p\n  q\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code>p\nq\n</code></pre>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('aContinuedBlankLeavesTheCloserReachable')]
    public function testAContinuedBlankKeepsTheFenceArmed(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }
}
