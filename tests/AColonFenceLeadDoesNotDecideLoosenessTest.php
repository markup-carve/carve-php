<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1a: the item's FIRST BLOCK does not matter.
 *
 * L1 asks whether the item holds a blank-line-separated second paragraph. It
 * does not ask what the first block was, and a rule that changed with the
 * first block's kind would need a reason there is none of - which is why L1a
 * was written down when a sub-list lead was the one shape that behaved
 * differently (markup-carve/carve#538).
 *
 * A colon-fence lead was the second. When an item's content opens a `:::`
 * container, `BlockParser` keeps the whole item stream together so the fence
 * captures its body, and that branch never ran the blank-line scan the plain
 * path runs - the same omission that was fixed one branch up for the sub-list
 * lead (carve-php#681). So `- ::: d` / `b` / `:::` / blank / `Body.` stayed
 * tight while every other lead loosened, and 362-3's `list.tight` came back
 * `true` where carve-js and carve-rs both report `false`
 * (carve-php#1450, markup-carve/carve-php#1450).
 *
 * The predicate is the shared one, so it answers by the item's INTERIOR and
 * never consults the closer: carve#326 C's interior blank inside verbatim
 * payload still does not loosen, and a blank between two of a `:::`
 * container's blocks still does, written closer or not.
 */
class AColonFenceLeadDoesNotDecideLoosenessTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * A blank-line-separated second paragraph after a colon-fence lead. Every
     * one of these renders `Body.` bare - no `<p>` - before the fix.
     *
     * @return array<string, array{string, string}>
     */
    public static function colonFenceLeads(): array
    {
        return [
            'a div' => [
                "- ::: d\n  b\n  :::\n\n  Body.\n",
                "<ul>\n  <li>\n    <div class=\"d\">\n      <p>b</p>\n    </div>\n    <p>Body.</p>\n  </li>\n</ul>\n",
            ],
            'an admonition' => [
                "- ::: note\n  b\n  :::\n\n  Body.\n",
                "<ul>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>b</p>\n    </aside>\n    <p>Body.</p>\n  </li>\n</ul>\n",
            ],
            'a longer colon fence' => [
                "- ::::: d\n  b\n  :::::\n\n  Body.\n",
                "<ul>\n  <li>\n    <div class=\"d\">\n      <p>b</p>\n    </div>\n    <p>Body.</p>\n  </li>\n</ul>\n",
            ],
            'an ordered marker, whose content column differs' => [
                "1. ::: d\n   b\n   :::\n\n   Body.\n",
                "<ol>\n  <li>\n    <div class=\"d\">\n      <p>b</p>\n    </div>\n    <p>Body.</p>\n  </li>\n</ol>\n",
            ],
            'the item reached through a quote' => [
                "> - ::: d\n>   b\n>   :::\n>\n>   Body.\n",
                "<blockquote>\n  <ul>\n    <li>\n      <div class=\"d\">\n        <p>b</p>\n      </div>\n      <p>Body.</p>\n    </li>\n  </ul>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('colonFenceLeads')]
    public function testASecondParagraphAfterAColonFenceLeadLoosensTheItem(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * THE BOUND: the same document with a lead of every other kind. These all
     * loosened before the fix and must still loosen after it - they are what
     * makes the colon fence's answer wrong rather than merely different.
     *
     * @return array<string, array{string}>
     */
    public static function otherLeads(): array
    {
        return [
            'plain text' => ["- a\n\n  Body.\n"],
            'a sub-list' => ["- - a\n\n  Body.\n"],
            'a heading' => ["- # h\n\n  Body.\n"],
            'a quote' => ["- > q\n\n  Body.\n"],
            'a code fence' => ["- ```\n  b\n  ```\n\n  Body.\n"],
            'a table' => ["- | a |\n\n  Body.\n"],
        ];
    }

    #[DataProvider('otherLeads')]
    public function testEveryOtherLeadStillLoosens(string $source): void
    {
        $this->assertStringContainsString(
            '<p>Body.</p>',
            $this->converter->convert($source),
            'a lead that already loosened stopped loosening',
        );
    }

    /**
     * The AST row `PART 12 conformance` reports, which no HTML can show: the
     * item holds only the container, so `<li>` wraps no paragraph either way
     * and `list.tight` is the only place the answer appears. Corpus document
     * 362-3.
     */
    public function testTheUnterminatedDivReportsALooseList(): void
    {
        $document = $this->converter->parse("- ::: d\n  b\n\n  tail\n");
        $list = $document->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertFalse($list->isTight(), 'the blank between the div\'s two blocks did not reach the list');
    }

    /**
     * INTENDED SURVIVORS. Each has a blank line inside an item whose lead is a
     * container, and each must stay TIGHT, so a fix that loosens on the blank
     * alone fails here rather than in the corpus.
     *
     * The first two are carve#326 C: a blank inside VERBATIM payload is
     * content, not a block separator, and that holds whether or not the fence
     * is ever closed - the shared predicate tracks open code fences for exactly
     * this. The third is §17 L2, where the blank precedes a sub-BLOCK rather
     * than a paragraph. The last has no blank at all.
     *
     * @return array<string, array{string, string}>
     */
    public static function tightShapes(): array
    {
        return [
            'an interior blank in a closed code fence' => [
                "- ```\n  a\n\n  b\n  ```\n",
                "<ul>\n  <li>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'an interior blank in an unclosed code fence' => [
                "- ```\n  b\n\n  tail\n",
                "<ul>\n  <li>\n    <pre><code>b\n\ntail\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'L2: the blank precedes a sub-block, not a paragraph' => [
                "- ::: d\n  b\n  :::\n\n  ::: e\n  c\n  :::\n",
                "<ul>\n  <li>\n    <div class=\"d\">\n      <p>b</p>\n    </div>\n    <div class=\"e\">\n      <p>c</p>\n    </div>\n  </li>\n</ul>\n",
            ],
            'no blank at all' => [
                "- ::: d\n  b\n  :::\n",
                "<ul>\n  <li>\n    <div class=\"d\">\n      <p>b</p>\n    </div>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('tightShapes')]
    public function testTheItemStaysTight(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
