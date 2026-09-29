<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The band between an item's marker column and its content column reaches the
 * item only as a LAZY LINE, and a lazy line continues an open paragraph and
 * nothing else (PART 0 S4). carve-php#2715 settled the first half; the second was
 * never asked, so a band follower joined an item whose last block was a closed
 * fence, a heading, a table or a colon fence, none of which leave a paragraph
 * open (carve-php#2724).
 *
 * A line that CLOSED NO BLOCK is the exception: after a comment the follower is
 * still the item's, which corpus category 517 pins in five documents.
 *
 * Expectations measured by running `scripts/spec/layout.mjs` and
 * `scripts/spec/html.mjs` at carve `e778d33a`, and confirmed unchanged at
 * `9b938e8a` (this repo's corpus pin) and at `89157529`.
 *
 * Each shape is asserted on a fresh converter and on a warm one:
 * `Performance\BorrowedHtmlLayout` writes HTML without building a tree and its
 * plan only applies while the converter is still cold (carve-php#2721).
 */
class ABandFollowerNeedsAnOpenParagraphTest extends TestCase
{
    /**
     * The item's last block leaves nothing open, so the follower is the
     * document's. Every row read the other way before carve-php#2724.
     *
     * @return array<string, array{string, string}>
     */
    public static function closedBlocks(): array
    {
        return [
            'a closed code fence' => [
                "- t\n\n  ```\n  q\n  ```\n z\n",
                "<ul>\n  <li>t\n    <pre><code>q\n</code></pre>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            // The payload does not matter: a comment run inside verbatim content
            // is not read as a comment.
            'a closed fence holding a comment run' => [
                "- t\n\n  ```\n  %%%\n  ```\n z\n",
                "<ul>\n  <li>t\n    <pre><code>%%%\n</code></pre>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a closed raw block' => [
                "- t\n\n  ```=html\n  q\n  ```\n z\n",
                "<ul>\n  <li>t\n    q\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a closed colon fence' => [
                "- t\n\n  ::: n\n  q\n  :::\n z\n",
                "<ul>\n  <li>t\n    <div class=\"n\">\n      <p>q</p>\n    </div>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a heading' => [
                "- t\n\n  # h\n z\n",
                "<ul>\n  <li>t\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a table' => [
                "- t\n\n  | a |\n  | - |\n z\n",
                "<ul>\n  <li>t\n    <table>\n      <thead>\n        <tr><th scope=\"col\">a</th></tr>\n      </thead>\n"
                    . "    </table>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a deeper band under a wide marker' => [
                "10. t\n\n    ```\n    q\n    ```\n  z\n",
                "<ol start=\"10\">\n  <li>t\n    <pre><code>q\n</code></pre>\n  </li>\n</ol>\n<p>z</p>\n",
            ],
        ];
    }

    /**
     * Something is still open, or nothing was closed, so the follower stays where
     * it was. None of these rows moves.
     *
     * @return array<string, array{string, string}>
     */
    public static function nothingClosed(): array
    {
        return [
            'a paragraph' => [
                "- t\n\n  p\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>p\nz</p>\n  </li>\n</ul>\n",
            ],
            'a quote' => [
                "- t\n\n  > q\n z\n",
                "<ul>\n  <li>t\n    <blockquote><p>q\nz</p></blockquote>\n  </li>\n</ul>\n",
            ],
            'a nested list' => [
                "- t\n\n  - q\n z\n",
                "<ul>\n  <li>t\n    <ul>\n      <li>q\nz</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'a comment line' => [
                "- t\n\n  %% c\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>\n",
            ],
            'a comment fence' => [
                "- t\n\n  %%%\n  c\n  %%%\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>\n",
            ],
            // The band of a NESTED item is still inside the outer one, so the
            // follower leaves the inner item and stops there.
            "a nested item's fence, where the outer item holds the band" => [
                "- a\n  - t\n\n    ```\n    q\n    ```\n   z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>t\n        <pre><code>q\n</code></pre>\n      </li>\n    </ul>\n"
                    . "    z\n  </li>\n</ul>\n",
            ],
        ];
    }

    /**
     * The three terms, each removed in turn. Every row here reads the same before
     * and after carve-php#2724, which is what says the shape needs all three and
     * that the gate answers no wider question.
     *
     * @return array<string, array{string, string}>
     */
    public static function oneTermRemoved(): array
    {
        return [
            'the band column, written at the content column instead' => [
                "- t\n\n  ```\n  q\n  ```\n  z\n",
                "<ul>\n  <li>t\n    <pre><code>q\n</code></pre>\n    z\n  </li>\n</ul>\n",
            ],
            'the blank line after the marker' => [
                "- t\n  ```\n  q\n  ```\n z\n",
                "<ul>\n  <li>t\n    <pre><code>q\n</code></pre>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a second blank, which closes the collected stream' => [
                "- t\n\n  ```\n  q\n  ```\n\n z\n",
                "<ul>\n  <li>t\n    <pre><code>q\n</code></pre>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
        ];
    }

    #[DataProvider('closedBlocks')]
    #[DataProvider('nothingClosed')]
    #[DataProvider('oneTermRemoved')]
    public function testAFreshConverterPlacesTheFollower(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('closedBlocks')]
    #[DataProvider('nothingClosed')]
    #[DataProvider('oneTermRemoved')]
    public function testAWarmConverterPlacesTheFollower(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $converter->convert("warm\n");

        $this->assertSame($expected, $converter->convert($source));
    }
}
