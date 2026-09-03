<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition written inside a FOOTNOTE BODY that has a list open in it
 * registers against the innermost open container the line REACHES, so one
 * strictly between the note's column and the item's reaches the NOTE.
 *
 * markup-carve/carve#1921 has list items, definition bodies and footnote
 * bodies apply ONE reach rule, so PART 0's `CARVE-P0-020` AT OR PAST MEANS
 * THE DEEPEST COLUMN THE LINE REACHES answers the definition whatever pair of
 * hosts opened the columns. PART 9 §16 puts the note's content column at 2,
 * and a bullet inside it opens an item at 4, so column 3 reaches the note and
 * nothing else: the definition registers there and the item holds only `a`.
 *
 * carve-php#1871, #1873 and #1878 each fixed one classification site - the
 * shared trailing-block tracker, the appending branch and the description
 * body's push branch. This host was the fourth and none of them reached it
 * (carve-php#1879); corpus `447-*-7` is the row it left failing.
 *
 * THE PASS RUNS AFTER THE AUTHORED-BASE REBASE, not in the collector where
 * the `dd` host takes it. carve#1729 gives an over-indented body an authored
 * local base that `rebaseOverindentedItemBlocks()` reads off the collected
 * lines as a group, so taking one line's indentation off before it runs
 * changes the base it computes - `testAUniformlyIndentedBodyKeepsItsAuthoredBase`
 * is that control, and it is corpus `417-*-4`'s geometry.
 *
 * Measured against the executable spec at markup-carve/carve `2f654da9`, the
 * tip of `main` and the revision corpus section 447 lives on, and at
 * `95fc3a04`, the revision `tests/spec` is pinned to - section 447 does not
 * exist at the pin, so the pinned corpus neither passes nor fails these rows;
 * and against carve-js `4627270e`, which answers every row below alike. Not
 * measured against carve-rs: the published `0.1.4` artifact is short of this
 * rule family - it fails corpus `441-*-2` - so it is not a current oracle,
 * and a claim with no revision beside it is one nobody can re-check.
 */
class ADefinitionBetweenAFootnoteBodysColumnAndANestedItemsReachesTheNoteTest extends TestCase
{
    /**
     * The answer every reaching row gives: `[r][]` resolves and the item holds
     * only its own text.
     *
     * @var string
     */
    protected const REACHED = "<p>See <a href=\"/url\">r</a> and <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n"
        . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
        . "      <p>b</p>\n      <ul>\n        <li>a</li>\n      </ul>\n"
        . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The note's content column is 2 and the item's is 4. Column 0 is the
     * document's own, 2 is the note's, 3 is the band this ticket is about, and
     * 4 to 6 are the item's - every one of them leaves the note holding the
     * definition, because at the item's column §10 I5 still has it interrupt
     * the item's paragraph and the note is what encloses it.
     *
     * Column 1 is deliberately absent: it is BELOW the note's column, so it
     * ends the body and is a document-level line rather than a definition
     * reaching anything - `testBelowTheNotesColumnEndsTheBody` pins it.
     *
     * @return array<string, array{0: int}>
     */
    public static function reachingBandProvider(): array
    {
        return [
            'column 0' => [0],
            'at the note column' => [2],
            'past the note column, below the item' => [3],
            'at the item column' => [4],
            'one past the item column' => [5],
            'two past the item column' => [6],
        ];
    }

    #[DataProvider('reachingBandProvider')]
    public function testTheReferenceDefinitionBand(int $column): void
    {
        $html = $this->converter->convert(
            "[^f]: b\n\n  - a\n" . str_repeat(' ', $column) . "[r]: /url\n\nSee [r][] and [^f].",
        );

        $this->assertSame(self::REACHED, trim($html));
    }

    /**
     * THE OTHER DEFINITION SPELLING REACHES TOO, matching the band
     * carve-php#1878 pins for the `dd` host: a nested `[^g]: x` between the
     * note's column and the item's becomes a sibling note rather than the
     * item's prose. Narrowing the pass to reference definitions alone leaves
     * this row folding into the item.
     */
    public function testANestedNoteDefinitionReachesTheNoteToo(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  - a\n   [^g]: x\n\nSee [^f] and [^g].");

        $this->assertStringContainsString('<li>a</li>', $html);
        $this->assertStringContainsString('id="fn2"', $html);
    }

    /**
     * An abbreviation definition is the third spelling the one predicate
     * covers, and it leaves the item on the same column. It expands nothing
     * here - the definition is scoped to the note body it registered in, and
     * `HTML` outside the note is not in that scope - so the assertion is that
     * the ITEM is clean, which is what the reach rule decides.
     */
    public function testAnAbbreviationDefinitionLeavesTheItem(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  - a\n   [*HTML]: HyperText\n\nHTML and [^f].");

        $this->assertStringContainsString('<li>a</li>', $html);
        $this->assertStringNotContainsString('[*HTML]', $html);
    }

    /**
     * BELOW THE NOTE'S COLUMN THE BODY ENDS. Column 1 reaches neither the note
     * nor the document's own column, so the line is an ordinary paragraph and
     * `[r][]` never resolves. Without this row the band above is satisfied by a
     * rule that erases every indent it sees.
     */
    public function testBelowTheNotesColumnEndsTheBody(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  - a\n [r]: /url\n\nSee [r][] and [^f].");

        $this->assertStringContainsString('<p>[r]: /url</p>', $html);
        $this->assertStringContainsString('See [r][] and', $html);
    }

    /**
     * AT THE ITEM'S COLUMN THE INDENTATION STAYS. The definition registers
     * against the note either way, but the residual column is what keeps the
     * item's LATER content inside it, so erasing it there would end the list
     * and leave `tail` outside. This is the control that separates the reach
     * rule from "erase whatever is indented".
     */
    public function testTheItemKeepsCollectingAtItsOwnColumn(): void
    {
        $html = $this->converter->convert(
            "[^f]: b\n\n  - a\n    [r]: /url\n\n    tail\n\nSee [r][] and [^f].",
        );

        $this->assertStringContainsString('<p>tail</p>', $html);
        $this->assertStringContainsString('<a href="/url">r</a>', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    /**
     * A UNIFORMLY INDENTED BODY KEEPS ITS AUTHORED BASE. carve#1729 lets a note
     * write its whole body at one column; the rebase takes that base off as a
     * group, and this pass runs after it and so sees a flush body with nothing
     * to do. Running it BEFORE the rebase changes the base it computes and
     * drops the rest of the body - corpus `417-*-4`'s geometry.
     */
    public function testAUniformlyIndentedBodyKeepsItsAuthoredBase(): void
    {
        $html = $this->converter->convert(
            "[^outer]: intro\n\n     [^inner]: note\n\n     see[^inner]\n\nsee[^outer]",
        );

        $this->assertStringContainsString('<p>intro</p>', $html);
        $this->assertStringContainsString('id="fnref2"', $html);
        $this->assertStringContainsString('<p>note', $html);
    }

    /**
     * A NESTED NOTE IS A CONTAINER THE COLUMN CANNOT SEE. A footnote body is
     * the one container the tracker carries WITHOUT a nested column, so the
     * reach test reads 0 for it and would take a definition belonging to the
     * INNER note - ending its body early and moving the rest into the outer
     * one. This is the shape that says the `inFootnoteBody` guard is doing
     * work; carve-js `4627270e` keeps `See [r][]` out of the outer note too.
     */
    public function testANestedNotesOwnDefinitionStaysInIt(): void
    {
        $html = $this->converter->convert(
            "[^f]: outer\n\n   [^g]: inner\n\n     [r]: /url\n\n     See [r][].\n\nx[^f]",
        );

        $this->assertStringNotContainsString('See <a href="/url">r</a>', $html);
        $this->assertStringContainsString('<p>outer<a href="#fnref1"', $html);
    }

    /**
     * NOT UNDER AN OPAQUE BLOCK. Inside a code fence the indentation is content
     * rather than a base, so the definition stays where it was written and is
     * never read as one.
     */
    public function testAFenceKeepsItsOwnIndentation(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  ```\n   [r]: /url\n  ```\n\nSee [r][] and [^f].");

        $this->assertStringContainsString('<pre><code> [r]: /url', $html);
        $this->assertStringContainsString('See [r][] and', $html);
    }

    /**
     * THE ITEM HOST AT THE DOCUMENT ROOT IS THE CONTROL THAT MUST NOT MOVE. A
     * list item at column 0 encloses no container, so a definition below its
     * content column reaches nothing and folds as lazy continuation. carve-js
     * `4627270e` folds it too. If this row ever resolves, the reach rule has
     * been applied where there is no outer column to reach.
     */
    public function testAnItemAtTheRootStillFolds(): void
    {
        $html = $this->converter->convert("- a\n [r]: /url\n\nSee [r][].");

        $this->assertStringContainsString("<li>a\n[r]: /url</li>", $html);
        $this->assertStringContainsString('See [r][].', $html);
    }
}
