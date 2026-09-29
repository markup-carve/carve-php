<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One item tree came out two ways depending on the authored column of its second
 * paragraph (markup-carve/carve#2558, corpus 517; markup-carve/carve-php#2707).
 *
 * §17 L1 asks whether the item holds a blank-line-separated second PARAGRAPH and
 * L1b says an invisible line is not the separator. Neither clause carries a
 * column term, and L1c already reads the sibling half at the list's level rather
 * than the item's - so the band between the marker column and the content column
 * is not where the item ends, and the content-column answer is the answer at
 * every column the item's own paragraph text reaches.
 *
 * TWO INDEPENDENT CAUSES, measured apart before either was touched. The looseness
 * scan read the item as ended at the content column, so a band paragraph never
 * reached the second-paragraph question: 54 of 180 band/content-column pairs
 * whose layout trees were identical disagreed on tightness. Separately, the item
 * collector ended the item at a band-column list MARKER written after an
 * invisible line, producing three lists where two columns over it produces a
 * nested one. The first is tightness, the second is ownership, and they share no
 * code.
 *
 * A `%%%` SPAN IS AN INVISIBLE LINE TOO, and the scan steps over the whole span.
 * Its payload is still not the second paragraph - that is what the old "return
 * the opener" branch protected - but the paragraph BEHIND its closer is, and it
 * never reached the question. An opener with NO closer has no line behind it, so
 * the item stays tight; the oracle reads it that way at either column, and
 * getting that arm wrong first is why it has a row here.
 */
class ABandParagraphAfterAnInvisibleLineLeavesTheItemLooseTest extends TestCase
{
    protected function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * The five documents of corpus category 517, byte for byte.
     *
     * @return array<string, array{string, string}>
     */
    public static function corpus517(): array
    {
        return [
            'the narrowest band' => [
                "- t\n\n  %% c\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>",
            ],
            'a three-column band under a four-wide marker' => [
                "10. t\n\n    %% c\n   z\n",
                "<ol start=\"10\">\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ol>",
            ],
            "that band's content-column twin" => [
                "10. t\n\n    %% c\n    z\n",
                "<ol start=\"10\">\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ol>",
            ],
            'the %%% span spelling' => [
                "- t\n\n  %%%\n  c\n  %%%\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>",
            ],
            'the attached sub-list that stays tight' => [
                "- t\n\n  %% c\n - b\n- s\n",
                "<ul>\n  <li>t\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n  <li>s</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('corpus517')]
    public function testTheCorpusDocumentReadsAsPinned(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invisibleKinds(): array
    {
        return [
            'comment' => ["%% c\n"],
            'fenced comment span' => ["%%%\nc\n%%%\n"],
            'attribute line' => ["{.k}\n"],
            'link reference definition' => ["[r]: u\n"],
        ];
    }

    /**
     * Every column of the band answers what the content column answers.
     *
     * The pair is the evidence, not the single reading: a band spelling that
     * happened to agree because BOTH went tight would pass a one-sided
     * assertion. The follower is prose, because that is the case the two
     * spellings disagreed on - a block opener is not a second paragraph at
     * either column.
     */
    #[DataProvider('invisibleKinds')]
    public function testTheBandAgreesWithTheContentColumn(string $invisible): void
    {
        foreach (['- ' => 2, '10. ' => 4] as $marker => $content) {
            $body = '';
            foreach (explode("\n", rtrim($invisible, "\n")) as $line) {
                $body .= str_repeat(' ', $content) . $line . "\n";
            }
            $reference = $this->html($marker . "t\n\n" . $body . str_repeat(' ', $content) . "z\n");
            // The wrapped lead is what "loose" looks like, and the attribute
            // kind lands a class on the SECOND paragraph, so the lead is the
            // half to read.
            $this->assertStringContainsString('<p>t</p>', $reference, 'the content-column reading is loose');

            for ($column = 1; $column < $content; $column++) {
                $this->assertSame(
                    $reference,
                    $this->html($marker . "t\n\n" . $body . str_repeat(' ', $column) . "z\n"),
                    $marker . ' band column ' . $column,
                );
            }
        }
    }

    /**
     * A `%%%` opener with no closer leaves the item tight at either column.
     *
     * Everything below the opener is payload, so there is no line behind the
     * span for the blank to separate `t` from. A first pass here started the
     * scan past the OPENER rather than past the closer, which read the payload
     * as the second paragraph and loosened both spellings.
     */
    public function testAnUnterminatedSpanLeavesTheItemTight(): void
    {
        foreach ([' ', '  '] as $indent) {
            $this->assertSame(
                "<ul>\n  <li>t\n    c\nz\n  </li>\n</ul>",
                $this->html("- t\n\n  %%%\n  c\n" . $indent . "z\n"),
                'indent ' . strlen($indent),
            );
        }
    }

    /**
     * A band-column marker after an invisible line nests, and the list goes on.
     *
     * The ownership half. Written at the content column this already produced
     * one nested list inside the item and kept `s` as its sibling; written in
     * the band it produced three lists, because the collector ended the item at
     * the marker.
     */
    public function testABandColumnMarkerNestsInsideTheItem(): void
    {
        $expected = "<ul>\n  <li>t\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n  <li>s</li>\n</ul>";

        // The content-column spelling is the control: it read this way before.
        $this->assertSame($expected, $this->html("- t\n\n  %% c\n  - b\n- s\n"));
        $this->assertSame($expected, $this->html("- t\n\n  %% c\n - b\n- s\n"));
    }

    /**
     * A line at or below the MARKER column is out of the item.
     *
     * This is the floor the scan moved to, so it needs a row of its own: at the
     * marker column the collector detaches the paragraph to document level, and
     * a tightness answer that loosened the list there would be describing a tree
     * this parser does not build.
     */
    public function testTheMarkerColumnEndsTheItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>t</li>\n</ul>\n<p>z</p>",
            $this->html("- t\n\n  %% c\nz\n"),
        );
    }

    /**
     * The band reaches the item only as a LAZY line.
     *
     * A second blank closes the collected stream, so the band paragraph behind
     * one is at document level in both spellings - and a tightness answer that
     * loosened the list there would again be describing a tree this parser does
     * not build. The content column is the floor once a blank has intervened,
     * and the pair below is the whole rule: same document, one column apart, and
     * only the right-hand one is in the item.
     */
    public function testABlankPutsTheContentColumnFloorBack(): void
    {
        $this->assertSame(
            "<ul>\n  <li>t</li>\n</ul>\n<p>z</p>",
            $this->html("- t\n\n  %% c\n\n z\n"),
        );
        $this->assertSame(
            "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>",
            $this->html("- t\n\n  %% c\n\n  z\n"),
        );
    }

    /**
     * A span whose payload dedents out of the item takes the item's end with it.
     *
     * The scan steps over a span, so it has to stop where the collector stops:
     * with the payload at the marker column the item is over at that line, and a
     * paragraph written below the closer is nobody's second one. Skipping to the
     * closer regardless let a paragraph outside the list loosen it, which a codex
     * review caught and the oracle settled.
     */
    public function testASpanPayloadOutsideTheItemEndsIt(): void
    {
        $this->assertSame(
            "<ul>\n  <li>t</li>\n</ul>\n<p>x</p>\n<p>z</p>",
            $this->html("- t\n\n  %%%\nx\n  %%%\n  z\n"),
        );
    }

    /**
     * Only a MARKER is forwarded from the band, not every block opener.
     *
     * The ownership half reaches exactly the shape §17 L2 and the content-column
     * branch name. Written for any block-shaped line it moved 64 documents off
     * the oracle's reading: a band heading or quote behind an invisible line
     * stays at document level, the same as on the base.
     *
     * It leaves the list as a PARAGRAPH, not as the block its marker spells: at
     * column 1 it is a lazy line of the paragraph the blank would have opened.
     * That is what the oracle renders, and what the base rendered.
     *
     * @return array<string, array{string, string}>
     */
    public static function bandOpenersThatStayOutside(): array
    {
        return [
            'heading' => ['# h', '<p># h</p>'],
            'quote' => ['> q', '<p>&gt; q</p>'],
        ];
    }

    #[DataProvider('bandOpenersThatStayOutside')]
    public function testABandBlockOpenerThatIsNotAMarkerStaysAtDocumentLevel(string $opener, string $outside): void
    {
        $this->assertSame(
            "<ul>\n  <li>t</li>\n</ul>\n" . $outside,
            $this->html("- t\n\n  %% c\n " . $opener . "\n"),
        );
    }
}
