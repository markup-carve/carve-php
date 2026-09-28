<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function array_unique;
use function str_repeat;
use function str_replace;
use function trim;

/**
 * A `%%%` span's ownership is read at its OPENER's column, so where the closer
 * is written cannot move the line below it.
 *
 * `CARVE-P9-053` names the fence form as the one "whose body and closer travel
 * with its opener", and `CARVE-P0-013` adds that a `%%%` run "closes the span at
 * any column and ends no container". Two readings disagreed with that (ruled in
 * markup-carve/carve#2527, pinned as corpus category 512):
 *
 * - the item collectors ran their paragraph tracker over the span's PAYLOAD
 *   line, which is opaque, so the payload reopened the paragraph the opener had
 *   closed and the closer's position then decided whether anything closed it
 *   again;
 * - a closer below the item's content column then left the span's state at
 *   whatever that column says, rather than at what the opener established.
 *
 * The `%%` line form at the same column already answered the second way, so the
 * fence spelling carried a parameter no clause gives it.
 *
 * Each host is swept over every closer column from 0 to its opener's, because
 * the defect is a DEPENDENCY rather than one wrong answer: a guard that checks
 * one column cannot see it. The expected HTML is the corpus pair's, so the
 * sweep cannot converge on the wrong answer - which matters for the marker-line
 * host, whose columns already agreed while agreeing on the wrong reading.
 */
class ACommentSpanSCloserColumnDoesNotMoveTheItemSOwnershipTest extends TestCase
{
    /**
     * Host template with `%COL%` for the closer's indentation, the opener's own
     * column, and the HTML every closer column must produce.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function hosts(): array
    {
        return [
            // Corpus 512-4 / 512-5.
            'a flat item with a flush-left follower' => [
                "- item\n  %%%\n  hidden\n%COL%%%%\ntail\n",
                2,
                "<ul>\n  <li>item</li>\n</ul>\n<p>tail</p>",
            ],
            // Corpus 512-6 / 512-7.
            'depth two with a flush-left follower' => [
                "- a\n  - item\n    %%%\n    hidden\n%COL%%%%\ntail\n",
                4,
                "<ul>\n  <li>a\n    <ul>\n      <li>item</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>",
            ],
            // Corpus 512-9: the follower reaches the OUTER item's content column.
            'depth two with a follower at the outer content column' => [
                "- a\n  - item\n    %%%\n    hidden\n%COL%%%%\n  tail\n",
                4,
                "<ul>\n  <li>a\n    <ul>\n      <li>item</li>\n    </ul>\n    tail\n  </li>\n</ul>",
            ],
            // Corpus 512-8: an ordered marker whose content column is 3.
            'an ordered marker whose content column is three' => [
                "1. item\n   %%%\n   hidden\n%COL%%%%\ntail\n",
                3,
                "<ol>\n  <li>item</li>\n</ol>\n<p>tail</p>",
            ],
            // Corpus 512-10 / 512-11: the span is the marker line's own first
            // block (`CARVE-P0-007`), so it retains nothing for the follower.
            'a marker-line span with a below-column follower' => [
                "- %%%\n  hidden\n%COL%%%%\n tail\n",
                2,
                "<ul>\n  <li></li>\n</ul>\n<p>tail</p>",
            ],
            // Corpus 512-12.
            'a marker-line span with a flush-left follower' => [
                "- %%%\n  hidden\n%COL%%%%\ntail\n",
                2,
                "<ul>\n  <li></li>\n</ul>\n<p>tail</p>",
            ],
            // Corpus 512 / 512-2: the definition band carve#1909 asked for. The
            // payload is consumed at either closer column, so the reference
            // below stays literal.
            'a link definition inside the span' => [
                "- item\n  %%%\n  [r]: /url\n%COL%%%%\n\n[use][r]\n",
                2,
                "<ul>\n  <li>item</li>\n</ul>\n<p>[use][r]</p>",
            ],
        ];
    }

    #[DataProvider('hosts')]
    public function testEveryCloserColumnGivesTheOpenersAnswer(
        string $template,
        int $openerColumn,
        string $expected,
    ): void {
        $converter = new CarveConverter();
        $seen = [];
        for ($column = 0; $column <= $openerColumn; $column++) {
            $source = str_replace('%COL%', str_repeat(' ', $column), $template);
            $seen['closer at column ' . $column] = trim($converter->convert($source));
        }

        foreach ($seen as $label => $html) {
            $this->assertSame($expected, $html, $label);
        }
        $this->assertCount(1, array_unique($seen), 'the closer column still moves the answer');
    }

    /**
     * CONTROL - the `%%` LINE form closes no span, so it keeps its own retention
     * rule and the line below folds into the item (corpus 214-2). Without this
     * the sweep above could be satisfied by treating every `%%`-prefixed line
     * below the column as a span boundary.
     */
    public function testALineCommentBelowTheColumnStillRetainsTheFollower(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    b\n  </li>\n</ul>",
            trim((new CarveConverter())->convert("- a\n%% c\nb\n")),
        );
    }

    /**
     * CONTROL - a span whose opener is written at column 0 ends the item there,
     * which is the reading corpus 214 pins and the one this change must not move.
     */
    public function testAColumnZeroSpanStillEndsTheItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n<p>b</p>",
            trim((new CarveConverter())->convert("- a\n%%%\nc\n%%%\nb\n")),
        );
    }

    /**
     * A span with NO closer registers its payload, because §28 degrades the
     * opener to a line comment rather than opening a span. The definition band
     * above is only meaningful against this (corpus 512-3).
     */
    public function testAnUnclosedSpanStillRegistersTheDefinitionBelowIt(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n<p><a href=\"/url\">use</a></p>",
            trim((new CarveConverter())->convert("- item\n  %%%\n  [r]: /url\n\n[use][r]\n")),
        );
    }

    /**
     * A payload line BELOW the item's content column is not the span's: §24's
     * step walk ends the item there, so the opener degrades to the line form and
     * the dedented line is a document-level paragraph. Cross-read against a
     * carve-js build whose spec pin already carries the ruling, which writes
     * these bytes; the row exists because this is the one shape the change moves
     * that corpus 512 does not pin, and the flush-left-only spelling below is
     * the control that says the reading is not new.
     *
     * @return array<string, array{string, string}>
     */
    public static function payloadBelowTheColumn(): array
    {
        return [
            'one payload line at the column, one below it' => [
                "- item\n  %%%\n  hidden\nhidden2\n%%%\ntail\n",
                "<ul>\n  <li>item\n    hidden\n  </li>\n</ul>\n<p>hidden2</p>\n<p>tail</p>",
            ],
            'CONTROL - the only payload line is below the column' => [
                "- item\n  %%%\nhidden\n%%%\ntail\n",
                "<ul>\n  <li>item</li>\n</ul>\n<p>hidden</p>\n<p>tail</p>",
            ],
            'CONTROL - both payload lines at the column' => [
                "- item\n  %%%\n  hidden\n  hidden2\n%%%\ntail\n",
                "<ul>\n  <li>item</li>\n</ul>\n<p>tail</p>",
            ],
        ];
    }

    #[DataProvider('payloadBelowTheColumn')]
    public function testAPayloadLineBelowTheColumnLeavesTheSpan(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    /**
     * The payload stays invisible at every closer column: an ownership fix that
     * reached the answer by publishing the span's body would pass the sweep.
     */
    #[DataProvider('hosts')]
    public function testThePayloadNeverBecomesVisible(string $template, int $openerColumn, string $expected): void
    {
        unset($expected);
        $converter = new CarveConverter();
        for ($column = 0; $column <= $openerColumn; $column++) {
            $source = str_replace('%COL%', str_repeat(' ', $column), $template);
            $html = $converter->convert($source);
            $this->assertStringNotContainsString('hidden', $html, 'closer at column ' . $column);
            $this->assertStringNotContainsString('%%', $html, 'closer at column ' . $column);
        }
    }
}
