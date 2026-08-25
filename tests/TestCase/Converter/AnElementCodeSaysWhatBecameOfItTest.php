<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The element code is read off the outcome, not off which arm of the walk ran.
 *
 * `element-unwrapped` says something specific - the wrapper went and the
 * children stayed - and it was written for every element the importer has no
 * mapping for. True of a `<button>`, whose label comes through. False of a
 * `<canvas>`, an `<object>` or an `<iframe>`, whose emitted Carve is empty:
 * nothing was unwrapped there, the subtree was dropped (carve-php#1377).
 *
 * And `<input>` was listed as a KNOWN element, so a discarded one produced no
 * row at all - the one element in this importer that took content out of the
 * document and exited clean.
 *
 * SCOPE: the report only. Every test pins the emitted Carve first, so none of
 * them can pass by the element quietly starting or stopping to survive.
 */
class AnElementCodeSaysWhatBecameOfItTest extends TestCase
{
    /**
     * NOTHING SURVIVED, so the row says dropped.
     *
     * @param string $html
     * @param string $tag
     */
    #[DataProvider('droppedProvider')]
    public function testAnElementThatLeavesNothingBehindIsReportedAsDropped(string $html, string $tag): void
    {
        $this->assertSame('', $this->carve($html));
        $this->assertContains(
            ['element-dropped', 'warning', 'Dropped unsupported <' . $tag . '> element'],
            $this->diagnostics($html),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function droppedProvider(): array
    {
        return [
            // THE SILENT ONE. Every other discarded element produced at least
            // one row; this produced none.
            'an input in a paragraph' => ['<p><input hidden></p>', 'input'],
            'an input with a value' => ['<p><input type="text" value="v"></p>', 'input'],
            'an empty canvas' => ['<p><canvas hidden></canvas></p>', 'canvas'],
            'an object with only a data source' => ['<p><object data="x"></object></p>', 'object'],
            'an iframe with only a src' => ['<p><iframe src="x"></iframe></p>', 'iframe'],
            'an embed' => ['<p><embed src="x"></p>', 'embed'],
        ];
    }

    /**
     * THE CHILDREN CAME THROUGH, so the wrapper really was unwrapped.
     *
     * @param string $html
     * @param string $tag
     * @param string $carve
     */
    #[DataProvider('unwrappedProvider')]
    public function testAnElementWhoseChildrenSurviveIsReportedAsUnwrapped(string $html, string $tag, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertContains(
            ['element-unwrapped', 'info', 'Replaced unsupported <' . $tag . '> element with Carve span metadata'],
            $this->diagnostics($html),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unwrappedProvider(): array
    {
        return [
            'a button keeps its label' => ['<p><button disabled>b</button></p>', 'button', 'b'],
            'a textarea keeps its text' => ['<p><textarea>t</textarea></p>', 'textarea', 't'],
            'a select keeps its options' => ['<p><select><option>o</option></select></p>', 'select', 'o'],
            // THE CASE WORDS ALONE WOULD GET WRONG: the object carries no text
            // at all, and the image inside it comes through. Its children
            // survived through their ATTRIBUTES, so the trace has to count
            // those too.
            'an object around an image' => [
                '<p><object><img src="a.png" alt="A"></object></p>',
                'object',
                '![A](a.png)',
            ],
        ];
    }

    /**
     * THE THIRD ANSWER IS SILENCE, and it is why a tag cannot settle this.
     *
     * An `<input type="checkbox">` at the head of a list item is not lost: it
     * comes back as the task marker. Its own attributes are in the emitted
     * document, so it left a trace and there is nothing to report - while the
     * same `<input>` anywhere else leaves none and is reported dropped. A
     * per-type table would be needed to tell those apart by name; the emitted
     * document tells them apart by itself.
     *
     * @param string $html
     * @param string $carve
     */
    #[DataProvider('taskListProvider')]
    public function testAnInputThatComesBackAsATaskMarkerIsNotReported(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function taskListProvider(): array
    {
        return [
            'an unchecked task' => ['<ul><li><input type="checkbox"> task</li></ul>', '- [ ] task'],
            'a checked task' => ['<ul><li><input type="checkbox" checked> task</li></ul>', '- [x] task'],
        ];
    }

    /**
     * The same, one wrapper in.
     *
     * The `<label>` around the checkbox is unsupported and reports its own
     * unwrap, and the list's `class` is dropped - but neither row names the
     * `<input>`, because the marker it became is right there in the output.
     */
    public function testATaskMarkerBehindALabelStillNamesNoInput(): void
    {
        $html = '<ul class="task-list"><li><label><input type="checkbox"> Done</label></li></ul>';

        $this->assertSame('- [ ] Done', $this->carve($html));
        $this->assertSame(
            [
                ['attribute-dropped', 'info', 'Dropped unsupported attribute class on <ul>'],
                ['element-unwrapped', 'info', 'Replaced unsupported <label> element with Carve span metadata'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * An `<input>` that is NOT the task marker still reports, in the same
     * document as one that is.
     *
     * The two sit side by side here, so a fix that answered from the tag would
     * have to give them the same answer and one of them would be wrong.
     */
    public function testTwoInputsInOneDocumentGetDifferentAnswers(): void
    {
        $html = '<ul><li><input type="checkbox"> task</li></ul><p><input hidden></p>';

        $this->assertSame('- [ ] task', $this->carve($html));
        $this->assertSame(
            [['element-dropped', 'warning', 'Dropped unsupported <input> element']],
            $this->diagnostics($html),
        );
    }

    /**
     * A KNOWN element is still not reported, whether or not it is empty.
     *
     * An empty `<em>` produces nothing either, and nothing was lost with it -
     * the emphasis is representable and had no content to carry. The outcome
     * decides the code only where the importer has no mapping; it does not
     * turn every empty element into a loss.
     *
     * @param string $html
     */
    #[DataProvider('representableProvider')]
    public function testARepresentableElementIsNeverReported(string $html): void
    {
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function representableProvider(): array
    {
        return [
            'an empty emphasis' => ['<p><em hidden></em></p>'],
            'an empty paragraph' => ['<p></p>'],
            'a rule that comes through' => ['<hr hidden>'],
            'a disclosure that keeps its open state' => ['<details open><p>a</p></details>'],
        ];
    }

    /**
     * The attribute rows are unchanged by any of this.
     *
     * `markup-carve/carve-php#1374` removed the `attribute-dropped` rows for a
     * boolean on an element that discards it, and reporting the ELEMENT is
     * what covers them now - it needs no per-tag table of which attributes
     * were representable, and it names the thing that actually went.
     */
    public function testTheElementRowReplacesTheAttributeRowRatherThanJoiningIt(): void
    {
        $this->assertSame(
            [['element-dropped', 'warning', 'Dropped unsupported <input> element']],
            $this->diagnostics('<ul><li><input open> t</li></ul>'),
        );
    }

    /**
     * CONTENT IS CONTENT even when it carries no words and no attributes.
     *
     * Deciding the code by searching the emitted document for an element's
     * text would call both of these dropped, though the output shows exactly
     * what an unwrapping leaves behind. A false `element-dropped` on content
     * that is right there is a new wrong statement, where a missed one only
     * leaves the report where it already was - so having content at all is
     * what settles it.
     *
     * @param string $html
     * @param string $carve
     */
    #[DataProvider('wordlessContentProvider')]
    public function testContentWithNoWordsIsStillUnwrapped(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertContains(
            ['element-unwrapped', 'info', 'Replaced unsupported <button> element with Carve span metadata'],
            $this->diagnostics($html),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function wordlessContentProvider(): array
    {
        return [
            'punctuation only' => ['<p><button>...</button></p>', '...'],
            'a rule for a child' => ['<button><hr></button>', '---'],
        ];
    }

    /**
     * Whitespace is not content, so there was nothing to unwrap.
     */
    public function testAnElementHoldingOnlyWhitespaceIsDropped(): void
    {
        $this->assertSame('', $this->carve('<p><canvas>  </canvas></p>'));
        $this->assertSame(
            [['element-dropped', 'warning', 'Dropped unsupported <canvas> element']],
            $this->diagnostics('<p><canvas>  </canvas></p>'),
        );
    }

    /**
     * A subtree the importer drops whole is not content to unwrap either.
     *
     * The `<script>` never reaches the output, so the `<canvas>` around it had
     * nothing to leave behind and was dropped, not unwrapped. Both rows are
     * `element-dropped` and they name different elements.
     */
    public function testAnElementHoldingOnlyADroppedSubtreeIsDropped(): void
    {
        $html = '<p><canvas><script>x</script></canvas></p>';

        $this->assertSame('', $this->carve($html));
        $this->assertSame(
            [
                ['element-dropped', 'warning', 'Dropped unsupported <canvas> element'],
                ['element-dropped', 'warning', 'Dropped active <script> element'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * ONE SURVIVOR ANSWERS FOR ONE ELEMENT.
     *
     * Both inputs are spelled identically and only the first becomes a task
     * marker. Read from a set rather than spent from a budget, the one
     * checkbox in the output would answer for both and the second's loss would
     * go unreported - the same false negative
     * `importAttributeSurvived()` guards against one level down.
     */
    public function testOneSurvivingCheckboxDoesNotAnswerForTwo(): void
    {
        $html = '<ul><li><input type="checkbox"> task</li></ul><p><input type="checkbox"></p>';

        $this->assertSame('- [ ] task', $this->carve($html));
        $this->assertContains(
            ['element-dropped', 'warning', 'Dropped unsupported <input> element'],
            $this->diagnostics($html),
        );
    }

    /**
     * Asking whether an element survived must not spend an attribute's credit.
     *
     * The element questions read the same observation of the emitted document
     * that `importAttributeSurvived()` spends from. If they consumed from it,
     * an element row would silence the attribute row underneath it - the exact
     * false negative that budget exists to prevent.
     *
     * THE ORDER IS INCIDENTAL to what this pins, and independent budgets are
     * why it can be: the element row moved ahead of the attribute row in
     * carve-php#1737 and both rows are still here, which is the whole claim.
     */
    public function testAnElementQuestionDoesNotConsumeAnAttributeSurvivor(): void
    {
        $html = '<p><iframe src="x"></iframe></p>';

        $this->assertSame('', $this->carve($html));
        $this->assertSame(
            [
                ['element-dropped', 'warning', 'Dropped unsupported <iframe> element'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute src on <iframe>'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * @param string $html
     *
     * @return list<array{string, string, string}>
     */
    private function diagnostics(string $html): array
    {
        return array_map(
            static function (object $diagnostic): array {
                $row = $diagnostic->toArray();

                return [$row['code'], $row['severity'], $row['message']];
            },
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }
}
