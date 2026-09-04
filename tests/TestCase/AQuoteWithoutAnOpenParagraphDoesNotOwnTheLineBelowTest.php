<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1897.
 *
 * A quote owns its own `>` lines plus its LAZY RUN, and PART 1 S4 says a lazy
 * line continues an OPEN PARAGRAPH and nothing else. A quote that ended on a
 * heading, a table row, a bare `>`, a thematic break or a closed fence leaves
 * no paragraph for the line below to continue, so that line is the ITEM's and
 * takes the item's authored base.
 *
 * This engine kept it for the quote, which left it unrebased and rendered it as
 * literal text. The probe inside `rebaseOverindentedItemBlocks()` was the cause:
 * an uninterrupted quote line abandoned the whole rebase pass.
 *
 * The row names are grid coordinates - quote ending / payload / payload column.
 * Every expectation is the executable spec's answer, read from
 * `scripts/spec/layout.mjs` into `scripts/spec/html.mjs`; the pinned spec
 * 95fc3a04 and spec main 063656e7 agree on all 45.
 *
 * The prose payload is in the HOLDING set because it renders the same either
 * way - it is not evidence the ownership was right, only that this shape cannot
 * see it.
 */
class AQuoteWithoutAnOpenParagraphDoesNotOwnTheLineBelowTest extends TestCase
{
    #[DataProvider('movingProvider')]
    public function testTheItemOwnsTheLineBelowAClosedQuote(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movingProvider(): array
    {
        return [
            'closed-fence/definition/c3' => ["- a\n  > ``` x\n  > c\n  > ```\n   [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/definition/c4' => ["- a\n  > ``` x\n  > c\n  > ```\n    [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/definition/c5' => ["- a\n  > ``` x\n  > c\n  > ```\n     [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/heading/c3' => ["- a\n  > ``` x\n  > c\n  > ```\n   # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/heading/c4' => ["- a\n  > ``` x\n  > c\n  > ```\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/heading/c5' => ["- a\n  > ``` x\n  > c\n  > ```\n     # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/definition/c3' => ["- a\n  >\n   [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/definition/c4' => ["- a\n  >\n    [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/definition/c5' => ["- a\n  >\n     [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/heading/c3' => ["- a\n  >\n   # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/heading/c4' => ["- a\n  >\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'empty/heading/c5' => ["- a\n  >\n     # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/empty' => ["- a\n  >\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/fence-closed' => ["- a\n  > ``` x\n  > c\n  > ```\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/fence-open' => ["- a\n  > ``` x\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">\n</code></pre>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/heading' => ["- a\n  > # q2\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q2\">q2</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/table' => ["- a\n  > | a | b |\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/thematic' => ["- a\n  > ---\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/empty' => ["- a\n  > q\n  >\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/heading' => ["- a\n  > q\n  > # q2\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <p>q</p>\n      <h1 id=\"q2\">q2</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/table' => ["- a\n  > q\n  > | a | b |\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <p>q</p>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/thematic' => ["- a\n  > q\n  > ---\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <p>q</p>\n      <hr>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/definition/c3' => ["- a\n  > # q\n   [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/definition/c4' => ["- a\n  > # q\n    [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/definition/c5' => ["- a\n  > # q\n     [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/heading/c3' => ["- a\n  > # q\n   # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/heading/c4' => ["- a\n  > # q\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/heading/c5' => ["- a\n  > # q\n     # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/definition/c3' => ["- a\n  > | a | b |\n   [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/definition/c4' => ["- a\n  > | a | b |\n    [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/definition/c5' => ["- a\n  > | a | b |\n     [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/heading/c3' => ["- a\n  > | a | b |\n   # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/heading/c4' => ["- a\n  > | a | b |\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/heading/c5' => ["- a\n  > | a | b |\n     # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/definition/c3' => ["- a\n  > ---\n   [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/definition/c4' => ["- a\n  > ---\n    [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/definition/c5' => ["- a\n  > ---\n     [r]: /url\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/heading/c3' => ["- a\n  > ---\n   # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/heading/c4' => ["- a\n  > ---\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/heading/c5' => ["- a\n  > ---\n     # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
        ];
    }

    #[DataProvider('holdingProvider')]
    public function testTheQuoteKeepsOnlyItsLazyExtent(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function holdingProvider(): array
    {
        return [
            'closed-fence/prose/c3' => ["- a\n  > ``` x\n  > c\n  > ```\n   h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/prose/c4' => ["- a\n  > ``` x\n  > c\n  > ```\n    h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'closed-fence/prose/c5' => ["- a\n  > ``` x\n  > c\n  > ```\n     h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <pre><code class=\"language-x\">c\n</code></pre>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'empty/prose/c3' => ["- a\n  >\n   h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'empty/prose/c4' => ["- a\n  >\n    h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'empty/prose/c5' => ["- a\n  >\n     h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: no-para/para-only' => ["- a\n  > p2\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote><p>p2\n# h</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/fence-closed' => ["- a\n  > q\n  > ``` x\n  > c\n  > ```\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <p>q</p>\n      <pre><code class=\"language-x\">c\n</code></pre>\n      <p># h</p>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/fence-open' => ["- a\n  > q\n  > ``` x\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote><p>q\n<code> x\n# h</code></p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'fence-interaction: para/para-only' => ["- a\n  > q\n  > p2\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote><p>q\np2\n# h</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/prose/c3' => ["- a\n  > # q\n   h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'heading/prose/c4' => ["- a\n  > # q\n    h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'heading/prose/c5' => ["- a\n  > # q\n     h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/prose/c3' => ["- a\n  > | a | b |\n   h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/prose/c4' => ["- a\n  > | a | b |\n    h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'table-row/prose/c5' => ["- a\n  > | a | b |\n     h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>a</td><td>b</td></tr>\n        </tbody>\n      </table>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/prose/c3' => ["- a\n  > ---\n   h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/prose/c4' => ["- a\n  > ---\n    h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'thematic/prose/c5' => ["- a\n  > ---\n     h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <hr>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'open-paragraph/lazy-line/c4' => ["- a\n  > q\n    # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote><p>q\n# h</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'heading/heading/c2 at the item content column' => ["- a\n  > # q\n  # h\n\nafter\n", "<ul>\n  <li>a\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
        ];
    }
}
