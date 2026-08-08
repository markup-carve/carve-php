<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE RULE BEHIND markup-carve/carve-php#1077, SWEPT RATHER THAN PATCHED.
 *
 * A renderer that has to mark a position inside a string it is still building
 * needs a character the finished bytes will never carry by accident. A FIXED
 * character cannot give that guarantee: whatever it is, an author may write it,
 * and then the restore pass rewrites the author's own character into whatever
 * the marker stood for. carve#678 found four of these in the canonical writer
 * and #1077 found two more in the HTML target. A sweep for the RULE rather than
 * the instance found NINE fixed markers still in the engine, four of which
 * corrupt authored content today (markup-carve/carve-php#1087).
 *
 * `DocumentSentinels::pick()` is the remedy in every row: choose the markers
 * per render from code points the document does not contain, so a collision is
 * impossible by construction rather than unlikely by assumption.
 *
 * THE ASSERTION HAS TO BE ON THE BYTES. Every marker here is either a
 * private-use character or a NUL-delimited string, and neither is visible in a
 * rendered-string comparison - that is exactly how the DEL case hid.
 *
 * WHY IT MATTERS DESPITE BEING PRIVATE-USE CHARACTERS. None of this is a
 * security issue and none is likely in ordinary prose. It matters because "no
 * document contains this" is an assumption about SOURCE, and the node API lets
 * a host build any string it likes. That is precisely how #1077 was reachable:
 * its guard was believed unreachable because the parser rewrites NUL, and a
 * host-built `Text("a\0b")` rendered as `a`, newline, `b`. The source door was
 * closed and the API door was open.
 */
class FixedInBandSentinelsCollideWithAuthoredContentTest extends TestCase
{
    /**
     * SITE 1, AND THE WORST OF THEM: the canonical writer's marker-column tag.
     *
     * It did not merely drop a character. `CarveRenderer::MARKER_COLUMN` was the
     * fixed U+E010, tested with `str_starts_with()` against each continuation
     * line of a list item, and a continuation line the AUTHOR started with
     * U+E010 answered that test - so the character was eaten AND the line was
     * written back without the item's content column. The paragraph left the
     * list item it was in. That is a change to the document's BLOCK STRUCTURE,
     * not to one character, and `to_html(fmt(x)) == to_html(x)` (PART 11
     * section 1) fails with it.
     *
     * The tag is slot 5 of the run the writer already picks per document. It was
     * parked outside that run deliberately, because a tag inside a re-picked run
     * would be rewritten underneath itself - which is an argument for putting it
     * IN the run, where the six picked code points are distinct by construction,
     * not beside it at a fixed address.
     */
    public function testAnAuthoredMarkerColumnTagDoesNotDedentTheParagraphOutOfItsItem(): void
    {
        $source = "- item\n\n  \u{E010}cont\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
    }

    public function testTheSameItemWithoutTheTagIsUnchangedControl(): void
    {
        // CONTROL. Passes before and after, and no mutation of this defect
        // touches it: it says the writer's list handling did not change, only
        // its answer to the authored character did.
        $source = "- item\n\n  cont\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function markerColumnPositions(): array
    {
        return [
            'opening a continuation paragraph' => ["- item\n\n  \u{E010}cont\n"],
            'opening a second continuation paragraph' => ["- a\n\n  \u{E010}b\n\n  c\n"],
            'in a nested item' => ["- a\n\n  - b\n\n    \u{E010}c\n"],
            'in the item text itself' => ["- \u{E010}item\n"],
            'mid-line, where it was never a tag' => ["- item\n\n  co\u{E010}nt\n"],
        ];
    }

    #[DataProvider('markerColumnPositions')]
    public function testTheTagCharacterSurvivesWhereverItIsAuthored(string $source): void
    {
        // Stated as "the character is there AND nothing else moved", rather than
        // as a round-trip: the nested row is not a fixed point of the writer for
        // an unrelated reason (it tightens the outer list), and pinning the
        // whole output would make this row fail for a change that has nothing to
        // do with the tag. Its CONTROL is the same document with the character
        // removed, so the comparison isolates exactly the one difference.
        //
        // The last two rows are controls WITHIN the class: the tag is only
        // consumed at the start of a continuation line, so those positions
        // survived before the fix too. They are here so a run that fixed the
        // leading case by disabling the tag mechanism entirely - which would
        // re-break carve#861's attached continuation marker - cannot pass by
        // deleting code.
        $written = (new CarveConverter())->toCarve($source);
        $control = (new CarveConverter())->toCarve(str_replace("\u{E010}", '', $source));

        $this->assertStringContainsString("\u{E010}", $written);
        $this->assertSame($control, str_replace("\u{E010}", '', $written));
    }

    public function testAContinuationMarkerStillSitsAtTheItemMarkerColumn(): void
    {
        // The tag's REASON, kept green. PART 11 section 17 L3 puts a `+`
        // continuation marker and the block it attaches at the item's MARKER
        // column, not its content column. Removing the tag would satisfy every
        // row above and silently reintroduce carve#861.
        $source = "- a\n\n+ b\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
    }

    /**
     * SITE 2: the HTML target's `::: footnotes` placement marker.
     *
     * `HtmlRenderer::FOOTNOTES_PLACEMENT_SENTINEL` was the fixed string NUL +
     * `carve:footnotes-placement` + NUL, on the docblock's claim that it "uses a
     * control character that cannot appear in rendered HTML output" - the same
     * claim, in the same file, that #1077 had already falsified for U+0001.
     *
     * Source cannot supply it, because the parser rewrites an input NUL to
     * U+FFFD. THE NODE API CAN, and that is the whole point of the ticket: a
     * host-built text node carrying the string rendered a footnotes `div` in the
     * middle of the author's paragraph.
     */
    public function testAHostBuiltNodeCarryingThePlacementMarkerIsNotAFootnotesDiv(): void
    {
        $marker = "\x00carve:footnotes-placement\x00";
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('a' . $marker . 'b'));
        $document->appendChild($paragraph);

        $this->assertSame('<p>a' . $marker . "b</p>\n", (new HtmlRenderer())->render($document));
    }

    public function testAHostBuiltNodeWithoutTheMarkerIsUnchangedControl(): void
    {
        // CONTROL.
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('ab'));
        $document->appendChild($paragraph);

        $this->assertSame("<p>ab</p>\n", (new HtmlRenderer())->render($document));
    }

    public function testThePlacementBlockStillRelocatesTheEndnotes(): void
    {
        // The marker's REASON, kept green in both of its shapes: with footnotes
        // it moves the endnotes section up to the block, and without them it
        // degrades to an empty placeholder. A run that dropped the marker
        // mechanism would satisfy the row above and lose both.
        $html = (new CarveConverter())->convert("x[^f]\n\n::: footnotes\n:::\n\n[^f]: body\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString("\x00", $html);
    }

    public function testThePlacementBlockDegradesToAnEmptyDivWithoutFootnotes(): void
    {
        $this->assertSame(
            "<p>x</p>\n<div class=\"footnotes\"></div>",
            (new CarveConverter())->convert("x\n\n::: footnotes\n:::\n"),
        );
    }

    /**
     * SITE 3: the Markdown writer's narrowed-escape carriers.
     *
     * The ticket left this one open on purpose - deleting an authored
     * private-use character silently is defensible, but it wanted a written rule
     * rather than an implementation habit. THERE IS ONE, and it is the clause
     * PART 9 section 29 already settled for the C0 controls: those characters
     * are CONTENT (PART 7 makes every character that is not one of the four
     * whitespace characters content), and "a target that silently deletes
     * content is lossy rather than safe". The Markdown target emits the C0 class
     * for exactly that reason. Deleting U+E004..U+E006 was the same decision
     * applied to three private-use code points, so the clause covers it without
     * anything new being invented.
     *
     * `a<U+E004>b` came out `ab`. It comes out `a<U+E004>b` now.
     *
     * @return array<string, array{0: string}>
     */
    public static function narrowedCarriers(): array
    {
        return [
            'U+E004, the underscore carrier' => ["\u{E004}"],
            'U+E005, the hash carrier' => ["\u{E005}"],
            'U+E006, the bracket carrier' => ["\u{E006}"],
        ];
    }

    #[DataProvider('narrowedCarriers')]
    public function testAnAuthoredNarrowedCarrierReachesTheMarkdownTarget(string $character): void
    {
        $this->assertSame(
            'a' . $character . "b\n",
            CarveConverter::markdown()->convert('a' . $character . "b\n"),
        );
    }

    #[DataProvider('narrowedCarriers')]
    public function testTheSameDocumentWithoutTheCarrierIsUnchangedControl(string $character): void
    {
        // CONTROL, one per carrier. Unused parameter on purpose: the row exists
        // to say the Markdown target's ordinary answer did not move.
        unset($character);

        $this->assertSame("ab\n", CarveConverter::markdown()->convert("ab\n"));
    }

    #[DataProvider('narrowedCarriers')]
    public function testTheOtherTargetsKeptTheCarrierAllAlong(string $character): void
    {
        // The deletion was MARKDOWN-ONLY, which is what says it was the writer's
        // marker rather than a decision about the character: the HTML and plain
        // targets emitted it before this change and still do.
        $this->assertSame(
            '<p>a' . $character . "b</p>\n",
            (new CarveConverter())->convert('a' . $character . "b\n"),
        );
        $this->assertSame(
            'a' . $character . "b\n",
            CarveConverter::plainText()->convert('a' . $character . "b\n"),
        );
    }

    public function testTheNarrowedEscapeDecisionStillRunsOnTheLine(): void
    {
        // The carriers' REASON, kept green. PART 11 section 8a M1b decides `_`,
        // `#` and `[` on the EMITTED LINE, which is why they are deferred
        // through a marker at all. Dropping the marker mechanism would satisfy
        // every row above and re-break `company_id`.
        $this->assertSame("company_id\n", CarveConverter::markdown()->convert("company_id\n"));
        $this->assertSame("a \\_\\_b\n", CarveConverter::markdown()->convert("a __b\n"));
    }

    /**
     * SITE 4: the BBCode converter's protected-span stash.
     *
     * The key was the fixed `NUL B <index> NUL`. Unlike the Markdown converter
     * next door, which strips input NULs before its own identically shaped
     * stash runs, this one never enforced the assumption behind it. Two
     * different failures came out of the same collision, and the second is worse
     * than corruption:
     *
     * - an index the stash HAS substitutes an unrelated span of the same post;
     * - an index it does not have raised an uncaught TypeError out of the
     *   restore callback, so ordinary untrusted forum input crashed the caller.
     */
    public function testAnAuthoredStashKeySubstitutesNothing(): void
    {
        $this->assertSame(
            "*x* \x00B0\x00 tail\n",
            (new BbcodeToCarve())->convert("[b]x[/b] \x00B0\x00 tail"),
        );
    }

    public function testAnOutOfRangeStashKeyDoesNotThrow(): void
    {
        // Before the fix: `TypeError: ...{closure}(): Return value must be of
        // type string, null returned`, from a plain string a forum user could
        // post. The row is on the OUTPUT rather than on the absence of an
        // exception, because `expectNotToPerformAssertions()` would pass against
        // a converter that returned the empty string.
        $this->assertSame(
            "plain \x00B7\x00 tail\n",
            (new BbcodeToCarve())->convert("plain \x00B7\x00 tail"),
        );
    }

    public function testTheSamePostWithoutAStashKeyIsUnchangedControl(): void
    {
        // CONTROL.
        $this->assertSame("*x* tail\n", (new BbcodeToCarve())->convert('[b]x[/b] tail'));
    }

    public function testTheProtectedSpansStillSurviveCarveEscaping(): void
    {
        // The stash's REASON, kept green: the spans it protects must not pick up
        // Carve escapes. A run that removed the stash would satisfy the rows
        // above and start escaping inside `[code]`.
        $this->assertStringContainsString('a_b', (new BbcodeToCarve())->convert('[code]a_b[/code]'));
    }

    /**
     * THE PICKED RUNS MOVE, WHICH IS THE ONLY REASON ANY OF THE ABOVE HOLDS.
     *
     * Every fix here is the same one line: ask `DocumentSentinels` for a run the
     * document does not contain. This row proves the mechanism rather than one
     * of its consequences, by handing the renderers a document that occupies the
     * PREFERRED run of all three sites at once. Nothing may be lost.
     */
    public function testTheSearchGivesUpOnlyWhenTheRangeIsGenuinelyFull(): void
    {
        // A DOCBLOCK CLAIMING A CONSTRAINT THAT DID NOT HOLD, which is its own
        // recurring defect. The search advanced by a whole RUN, so it only ever
        // tested ALIGNED runs - about a thousand candidates for a run of six -
        // and its fallback comment said reaching it "would have to write every
        // private-use code point above $first". It did not: ONE character from
        // each aligned run, 1066 of them, exhausted the search and handed back
        // the colliding preferred run, which is the corruption every other row
        // in this file is about.
        //
        // The search advances one code point at a time now, so the fallback
        // needs the range covered with no gap of $count. The row below is the
        // document that used to defeat it.
        $occupied = '';
        for ($codepoint = 0xE001; $codepoint + 5 <= DocumentSentinels::PRIVATE_USE_LAST; $codepoint += 6) {
            $occupied .= (string)mb_chr($codepoint, 'UTF-8');
        }

        $run = DocumentSentinels::pick($occupied, 6, 0xE001);

        foreach ($run as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $occupied);
        }
    }

    public function testTheFirstFreeRunIsStillThePreferredOneControl(): void
    {
        // CONTROL. The common case must not have moved: a document containing
        // none of the candidates still gets the preferred run, so the step
        // change costs nothing for ordinary input.
        $this->assertSame(
            ["\u{E001}", "\u{E002}", "\u{E003}", "\u{E004}", "\u{E005}", "\u{E006}"],
            DocumentSentinels::pick('plain text', 6, 0xE001),
        );
    }

    public function testADocumentHoldingEveryPreferredRunIsRoundTripped(): void
    {
        $occupied = '';
        for ($codepoint = 0xE001; $codepoint <= 0xE012; $codepoint++) {
            $occupied .= (string)mb_chr($codepoint, 'UTF-8');
        }
        $source = 'a' . $occupied . "b\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
        $this->assertSame($source, CarveConverter::markdown()->convert($source));
        $this->assertSame(
            '<p>a' . $occupied . "b</p>\n",
            (new CarveConverter())->convert($source),
        );
    }
}
