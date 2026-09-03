<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §28's degradation is a CLASSIFICATION and it is TOTAL.
 *
 * An opener with no matching closer AHEAD is one `comment_line` for every
 * question the layout asks about it, container OWNERSHIP included
 * (markup-carve/carve#1903, written into §24 C3's comment exception and into
 * §28). At a container's own column 0 it therefore leaves the frame open
 * exactly as a `%%` line does, and the follower stays in the item.
 *
 * THE SUBSTITUTION IS THE RULE. Every row below has a `%% z` twin, and the two
 * must answer alike; a reading where one classification produces two ownership
 * answers is the defect. Measured over 600 such pairs - eight container hosts,
 * five columns, five follower shapes, three follower columns - the two answered
 * alike in 525 before this change and in all 600 after, which is what the
 * executable spec at markup-carve/carve `0b0edf50` gives.
 *
 * WHAT IT DOES NOT SETTLE, and what the controls here pin: a TERMINATED fence
 * at that column is a comment BLOCK and still ends the item (corpus 214), the
 * closer is looked for AHEAD rather than on the next line, and §24 C3's
 * exception is about ITEM ownership - a block quote has no such clause and a
 * comment at column 0 below one ends it in BOTH spellings.
 *
 * Measured against the executable spec at markup-carve/carve `0b0edf50`, the
 * tip of `main`; against `95fc3a04`, the revision `tests/spec` is pinned to;
 * and against carve-js `2bbf0ee`. Both of the latter predate the ruling and
 * still give the pre-change answer on the rows that move. No carve-rs reading
 * is claimed: no published artifact of that engine is current for this rule
 * family and it was not run.
 */
class ADegradedCommentFenceAtAContainersColumnZeroKeepsTheFollowerTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The eleven rows of corpus section 445. Seven move with this change; four
     * are the controls and are byte-identical before and after.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function corpusRowProvider(): array
    {
        $kept = "<ul>\n  <li>x\n    y\n  </li>\n</ul>";

        return [
            'a bullet host' => ["- x\n%%%\ny\n", $kept],
            'the line form it must equal' => ["- x\n%% z\ny\n", $kept],
            'a terminated fence still ends the item' => ["- x\n%%%\n%%%\ny\n", "<ul>\n  <li>x</li>\n</ul>\n<p>y</p>"],
            'an ordered host' => ["1. x\n%%%\ny\n", "<ol>\n  <li>x\n    y\n  </li>\n</ol>"],
            'a padded marker' => ["-   x\n%%%\ny\n", $kept],
            'a nested item at its own column 0' => [
                "- - x\n  %%%\n  y\n",
                "<ul>\n  <li>\n    <ul>\n      <li>x\n        y\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a wider unterminated run' => ["- x\n%%%%\ny\n", $kept],
            'a wider run below is not a closer' => ["- x\n%%%\n%%%%\ny\n", $kept],
            'the closer is looked for ahead' => ["- x\n%%%\ny\n%%%\nz\n", "<ul>\n  <li>x</li>\n</ul>\n<p>z</p>"],
            'the list stays open, not only the item' => ["- x\n%%%\n- y\n", "<ul>\n  <li>x</li>\n  <li>y</li>\n</ul>"],
            'a block quote has no such exception' => ["> x\n%%%\ny\n", "<blockquote><p>x</p></blockquote>\n<p>y</p>"],
        ];
    }

    #[DataProvider('corpusRowProvider')]
    public function testTheCorpusRow(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }

    /**
     * THE SUBSTITUTION, asked directly rather than through a pair of literals.
     * A degraded fence and a `%%` line are the same classification, so swapping
     * one for the other may not change the document - at the container's own
     * column 0 and at every column inside it.
     *
     * The two spellings answered alike at columns 1 and 2 before this change
     * and differed at 0, 3 and 4; the three sites it touches are what closed
     * that gap. Matches the executable spec at `0b0edf50` on every row.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function substitutionProvider(): array
    {
        $rows = [];
        foreach (['a bullet' => '- x', 'an ordered marker' => '1. x', 'a padded marker' => '-   x'] as $label => $host) {
            foreach ([0, 1, 2, 3, 4] as $column) {
                $rows[$label . ', column ' . $column] = [$host, $column];
            }
        }

        return $rows;
    }

    #[DataProvider('substitutionProvider')]
    public function testADegradedFenceAnswersLikeTheLineForm(string $host, int $column): void
    {
        $pad = str_repeat(' ', $column);
        $fence = $this->converter->convert($host . "\n" . $pad . "%%%\ny\n");
        $line = $this->converter->convert($host . "\n" . $pad . "%% z\ny\n");

        $this->assertSame(trim($line), trim($fence));
    }

    /**
     * A TERMINATED FENCE IS NOT DEGRADED and keeps its block, so the same
     * substitution must FAIL for it. Without this the whole class is satisfied
     * by a change that degrades every comment fence rather than only the
     * unterminated one, which is the overshoot corpus `445-*-3` names.
     *
     * @return array<string, array{0: int}>
     */
    public static function terminatedFenceProvider(): array
    {
        return [
            'column 0' => [0],
            'column 1' => [1],
            'column 2' => [2],
        ];
    }

    #[DataProvider('terminatedFenceProvider')]
    public function testATerminatedFenceStillDiffersFromTheLineForm(int $column): void
    {
        $pad = str_repeat(' ', $column);
        $fence = $this->converter->convert("- x\n" . $pad . "%%%\n" . $pad . "hidden\n" . $pad . "%%%\ny\n");
        $line = $this->converter->convert("- x\n" . $pad . "%% z\n" . $pad . "hidden\n" . $pad . "%% z\ny\n");

        $this->assertNotSame(trim($line), trim($fence));
        $this->assertStringNotContainsString('hidden', $fence, 'a terminated fence hides its body');
        $this->assertStringContainsString('hidden', $line, 'a line comment hides only its own line');
    }

    /**
     * THE DEGRADED FENCE CLAIMS NO EXTENT EITHER, which is the third site and
     * the one the column-0 rows cannot show. Written INSIDE the item, the
     * authored-base pass gave the opener a block extent and rebased the run
     * below it along with the opener, so `# y` arrived at the item's column 0
     * and opened a heading - where the same document with `%% z` folds it as
     * text. §28 gives an unterminated opener no block, so its extent is its own
     * line.
     *
     * Matches the executable spec at `0b0edf50`; carve-js `2bbf0ee` gives the
     * pre-change answer, its half of the ruling being open.
     *
     * @return array<string, array{0: string}>
     */
    public static function extentProvider(): array
    {
        return [
            'a heading below it' => ['# y'],
            'a definition below it' => ['[t]: /u'],
            'a code fence below it' => ["```\nc\n```"],
        ];
    }

    #[DataProvider('extentProvider')]
    public function testADegradedFenceInsideTheItemClaimsNoExtent(string $follower): void
    {
        $lines = explode("\n", $follower);
        $tail = implode("\n", array_map(static fn (string $l): string => ' ' . $l, $lines));
        $fence = $this->converter->convert("- x\n   %%%\n" . $tail . "\n");
        $line = $this->converter->convert("- x\n   %% z\n" . $tail . "\n");

        $this->assertSame(trim($line), trim($fence));
    }

    /**
     * THE BASE-ZERO OPAQUE BRANCH IS NOT A FOURTH SITE, and this pins that.
     * `rebaseOverindentedItemBlocks()` has a second comment-fence walk for an
     * opener already at the container's minimum column, and it still asks "is
     * this shaped like an opener?" rather than "does it have a closer ahead?" -
     * a code review read that as the same defect one site along.
     *
     * Measured instead: giving it the same rollback moves 18 documents and
     * takes agreement with the executable spec at `0b0edf50` from 38 of 68 down
     * to 28. It is right by a different mechanism - an unterminated opener
     * there runs its skip to the end of the run, so the lines below it are
     * never rebased at all, which is the same answer the rollback is for. The
     * rows below are the ones that would have moved.
     *
     * @return array<string, array{0: string}>
     */
    public static function baseZeroOpaqueProvider(): array
    {
        return [
            'a heading below it' => ['# y'],
            'a thematic break below it' => ['***'],
            'a table row below it' => ['| a |'],
        ];
    }

    #[DataProvider('baseZeroOpaqueProvider')]
    public function testAFenceAtTheItemsOwnColumnLeavesTheRunBelowItUnrebased(string $opener): void
    {
        foreach ([3, 4] as $column) {
            $html = $this->converter->convert("- x\n  %%%\n" . str_repeat(' ', $column) . $opener . "\ntail\n");

            $this->assertSame(
                "<ul>\n  <li>x\n    " . $opener . "\ntail\n  </li>\n</ul>",
                trim($html),
                'column ' . $column,
            );
        }
    }
}
