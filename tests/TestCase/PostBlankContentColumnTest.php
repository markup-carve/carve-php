<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Content-column model for list continuation (carve#295).
 *
 * A block opener or sublist marker belongs to the item only when it reaches the
 * item's content column (marker width + separator). Below it: after a blank the
 * item ends and the block parses at document level; with no blank it lazily
 * continues the item's paragraph as text. Above it: lazy paragraph text.
 */
class PostBlankContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function assertHtml(string $expected, string $carve): void
    {
        $this->assertSame(trim($expected), trim($this->converter->convert($carve)));
    }

    public function testBlockOpenerBelowContentColumnAfterBlankDetaches(): void
    {
        // `> q` is one column in, below the `- ` content column (2), after a
        // blank: the item ends and the quote parses at document level.
        $this->assertHtml(
            "<ul>\n  <li>one</li>\n</ul>\n<p>&gt; q</p>",
            "- one\n\n > q\n",
        );
    }

    public function testParagraphBelowContentColumnAfterBlankDetaches(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>one</li>\n</ul>\n<p>text</p>",
            "- one\n\n text\n",
        );
    }

    public function testBlockOpenerAboveContentColumnIsLazyText(): void
    {
        // Three columns in, above the content column: the heading marker is no
        // longer a block opener, it folds as lazy paragraph text (loose item).
        $this->assertHtml(
            "<ul>\n  <li><p>one</p>\n    <p># h</p>\n  </li>\n</ul>",
            "- one\n\n   # h\n",
        );
    }

    public function testBlockOpenerBelowContentColumnNoBlankIsLazyContinuation(): void
    {
        // No blank: the below-column `> q` lazily continues the item paragraph
        // as text rather than nesting a blockquote.
        $this->assertHtml(
            "<ul>\n  <li>one\n&gt; q</li>\n</ul>",
            "- one\n > q\n",
        );
    }

    public function testBlockOpenerAtContentColumnNests(): void
    {
        // At the content column the block opener nests, as before.
        $this->assertHtml(
            "<ul>\n  <li>one\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>",
            "- one\n\n  > q\n",
        );
    }

    public function testOrderedMarkerContentColumnIsMarkerWidth(): void
    {
        // `1. ` content column is 3, so two columns in is still below it.
        $this->assertHtml(
            "<ol>\n  <li>one</li>\n</ol>\n<p>&gt; q</p>",
            "1. one\n\n  > q\n",
        );
    }

    public function testDefinitionListInterruptsAtColumnZero(): void
    {
        // A `::` def-list term is a first-class block opener, so at column 0 it
        // interrupts the item and the whole list parses at document level.
        $this->assertHtml(
            "<ul>\n  <li>one</li>\n</ul>\n<dl>\n  <dt>term</dt>\n  <dd>def</dd>\n</dl>",
            "- one\n:: term\n:  def\n",
        );
    }

    public function testDefinitionListNestsAtContentColumn(): void
    {
        // At the content column the def-list nests as a whole `<dl>` in the item.
        $this->assertHtml(
            "<ul>\n  <li>one\n    <dl>\n      <dt>term</dt>\n      <dd>def</dd>\n    </dl>\n  </li>\n</ul>",
            "- one\n  :: term\n  :  def\n",
        );
    }

    public function testTableBelowContentColumnFoldsAllRowsAsLazyText(): void
    {
        // Below the content column a table is lazy text: BOTH rows fold into the
        // item paragraph rather than the second row splitting off (§24 C3).
        $this->assertHtml(
            "<ul>\n  <li>one\n|= H |\n| x |</li>\n</ul>",
            "- one\n |= H |\n | x |\n",
        );
    }

    public function testDefinitionListMismatchedIndentKeepsWholeDl(): void
    {
        // When the `:  def` line sits at a lower column than its `:: term`, the
        // definition still belongs to the def-list -- it must not strand as a
        // document-level `<p>`. A bare `:  def` is not an independent block
        // opener, so it never interrupts the item; the whole `<dl>` stays
        // together (matches carve-js).
        $this->assertHtml(
            "<ul>\n  <li>one\n    <dl>\n      <dt>term</dt>\n      <dd>def</dd>\n    </dl>\n  </li>\n</ul>",
            "- one\n  :: term\n:  def\n",
        );
    }
}
