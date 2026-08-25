<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A checkbox consumed into a task marker says nothing about itself
 * (carve-php#1705).
 *
 * `<input type="CHECKBOX">` at the head of a list item produces the right Carve
 * - `- [ ] a`, the same as the lowercase spelling, since carve-php#1704 made the
 * keyword match case-insensitively the way HTML does - and then reported
 * `attribute-dropped` and `element-dropped` about it. The checkbox was CONSUMED
 * into the `[ ]` marker, not dropped, so the report made a false statement about
 * a success: the direction this repo rates worst.
 *
 * THE CAUSE WAS NOT ANOTHER KEYWORD COMPARISON. Those are all gone. It was the
 * survival test, which asks whether any of the element's raw attribute VALUES
 * reappears in the RE-RENDERED document. The re-render writes `type="checkbox"`
 * in lowercase, so an element whose value was `CHECKBOX` found no match and was
 * called dropped.
 *
 * THE FIX RECORDS THE CONSUMED INPUT AT THE POINT OF CONSUMPTION, where the
 * writer is already holding the element it read the state off. Re-deriving which
 * input became the marker during the report walk would reintroduce a comparison
 * on the value, which is the shape that caused this.
 *
 * NOT BY FOLDING CASE IN THE TALLY, which would change what counts as survival
 * for every element and every attribute and could start suppressing real losses.
 * `testASurvivorStillAnswersForOnlyOneElement()` and
 * `testEveryOtherLossOnTheSameInputStillReports()` are what hold that line.
 *
 * SCOPE: THE REPORT ONLY. The emitted Carve was already correct for every
 * spelling, so a test that checked only the value would be a check that cannot
 * fail. Every case below asserts the DIAGNOSTICS, and pins the value beside them
 * so a silent report cannot pass by the marker quietly disappearing.
 */
class AConsumedCheckboxIsNotReportedAsDroppedTest extends TestCase
{
    /**
     * @return list<array{string, string, string}>
     */
    private function diagnostics(string $html): array
    {
        $report = (new HtmlToCarve())->convertWithReport($html);

        return array_map(
            static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->severity, $diagnostic->message],
            $report->diagnostics,
        );
    }

    private function carve(string $html): string
    {
        return (new HtmlToCarve())->convertWithReport($html)->value;
    }

    /**
     * THE DEFECT, one case per spelling.
     *
     * Each spelling is its own case rather than a loop inside one, so a red run
     * evaluates all of them instead of stopping at the first.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testAConsumedCheckboxReportsNothing(string $spelling): void
    {
        $html = '<ul><li><input type="' . $spelling . '"> a</li></ul>';

        $this->assertSame("- [ ] a\n", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * The checked spelling takes the same answer, and its own marker.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testACheckedConsumedCheckboxReportsNothing(string $spelling): void
    {
        $html = '<ul><li><input type="' . $spelling . '" checked> a</li></ul>';

        $this->assertSame("- [x] a\n", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function checkboxSpellingProvider(): array
    {
        return [
            'lowercase, which was always clean' => ['checkbox'],
            'uppercase' => ['CHECKBOX'],
            'capitalized' => ['Checkbox'],
            'mixed case' => ['chEckBox'],
            'mixed case the other way' => ['cHECKBOx'],
        ];
    }

    /**
     * A TIPTAP TASK ITEM KEEPS ITS STATE IN `data-checked` and wraps the input
     * in an empty `<label>`, which the content loop skips whole.
     *
     * That input is consumed by the marker exactly as a direct one is, and had
     * the same false rows for the same reason.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testALabelWrappedCheckboxReportsNothingAboutItself(string $spelling): void
    {
        $html = '<ul data-type="taskList"><li data-checked="false">'
            . '<label><input type="' . $spelling . '"></label><div>a</div></li></ul>';

        $this->assertSame("- [ ] a\n", $this->carve($html));

        $codes = array_column($this->diagnostics($html), 0);
        $this->assertNotContains('element-dropped', $codes);
        // The rows this shape DOES have belong to the editor's own markers and
        // to the `<label>`, not to the input.
        $this->assertSame(
            $this->diagnostics(str_replace($spelling, 'checkbox', $html)),
            $this->diagnostics($html),
            'every spelling must produce the report the lowercase one produces',
        );
    }

    /**
     * THE LINE THE NARROW FIX EXISTS TO HOLD: one survivor answers for one
     * element.
     *
     * Only the FIRST checkbox in an item becomes the marker; a second is dropped
     * like any other element and its loss must still be reported. A fix that
     * exempted every checkbox, or that folded case in the survival tally, would
     * lose this row - which is the false negative the budget was introduced to
     * prevent.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testASurvivorStillAnswersForOnlyOneElement(string $spelling): void
    {
        $html = '<ul><li><input type="' . $spelling . '"> task</li></ul>'
            . '<p><input type="' . $spelling . '"></p>';

        $this->assertSame("- [ ] task\n", $this->carve($html));
        $this->assertSame(
            [
                ['element-dropped', 'warning', 'Dropped unsupported <input> element'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute type on <input>'],
            ],
            $this->diagnostics($html),
            'the input that did NOT become a marker still reports its loss',
        );
    }

    /**
     * Two checkboxes in ONE item: the first is the marker, the second is a drop.
     *
     * Before the fix the two spellings disagreed about WHICH one to name. With
     * `CHECKBOX` first and `checkbox` second, the report named the first - the
     * consumed one - and said nothing about the second, so it was wrong twice in
     * the same document.
     */
    public function testTheSecondCheckboxInAnItemIsTheOneReported(): void
    {
        $html = '<ul><li><input type="CHECKBOX"><input type="checkbox"> a</li></ul>';

        $this->assertSame("- [ ] a\n", $this->carve($html));
        $this->assertSame(
            [
                ['element-dropped', 'warning', 'Dropped unsupported <input> element'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute type on <input>'],
            ],
            $this->diagnostics($html),
        );
        // Named on the SECOND input, not the first.
        $report = (new HtmlToCarve())->convertWithReport($html);
        foreach ($report->diagnostics as $diagnostic) {
            $this->assertSame('/ul[1]/li[1]/input[2]', $diagnostic->path);
        }
    }

    /**
     * THE EXEMPTION IS THE `type` QUESTION AND THE ELEMENT QUESTION, nothing
     * else on the element.
     *
     * An `onclick`, a `name` and a `value` on the consumed input are real losses
     * and the lowercase spelling reports them, so skipping the walk over the
     * element to remove two false rows would have taken these true ones with it.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testEveryOtherLossOnTheSameInputStillReports(string $spelling): void
    {
        $html = '<ul><li><input type="' . $spelling . '" onclick="x()" name="n" value="v"> a</li></ul>';

        $this->assertSame("- [ ] a\n", $this->carve($html));
        $this->assertSame(
            [
                ['attribute-dropped', 'warning', 'Dropped event-handler attribute onclick on <input>'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute name on <input>'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute value on <input>'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * THE CONTROL: an `<input>` that was NOT consumed still reports both rows.
     *
     * The exemption is keyed to the element the writer consumed, so it must not
     * reach a checkbox anywhere else in the document - including one spelled the
     * same way that never became a marker.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testACheckboxThatBecameNoMarkerStillReports(string $spelling): void
    {
        $html = '<p><input type="' . $spelling . '"></p>';

        $this->assertSame("\n", $this->carve($html));
        $this->assertSame(
            [
                ['element-dropped', 'warning', 'Dropped unsupported <input> element'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute type on <input>'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * EVERY SPELLING PRODUCES THE LOWERCASE SPELLING'S REPORT, swept.
     *
     * This is the ticket's actual complaint stated as one invariant: the
     * spellings are the same keyword to HTML and to this importer, so the report
     * must not be able to tell them apart. It also covers the shapes above from
     * the other direction, since it compares whole reports rather than asserting
     * one expected list.
     *
     * @param string $spelling
     */
    #[DataProvider('checkboxSpellingProvider')]
    public function testTheReportCannotTellTheSpellingsApart(string $spelling): void
    {
        $shapes = [
            '<ul><li><input type="%s"> a</li></ul>',
            '<ul><li><input type="%s" checked> a</li></ul>',
            '<ul><li>x <input type="%s"> a</li></ul>',
            '<ul><li><input type="%s" name="n"> a</li></ul>',
            '<ul><li><input type="%s"> a</li><li>b</li></ul>',
            '<ul><li><input type="%s"> task</li></ul><p><input type="%s"></p>',
            '<p><input type="%s"></p>',
        ];

        foreach ($shapes as $shape) {
            $count = substr_count($shape, '%s');
            $spelled = vsprintf($shape, array_fill(0, $count, $spelling));
            $lower = vsprintf($shape, array_fill(0, $count, 'checkbox'));

            $this->assertSame($this->carve($lower), $this->carve($spelled), $shape);
            $this->assertSame($this->diagnostics($lower), $this->diagnostics($spelled), $shape);
        }
    }
}
