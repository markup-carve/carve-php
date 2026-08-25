<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A survivor is credited to the element it came back on.
 *
 * The budget behind `importAttributeSurvived()` was tallied over the whole
 * emitted document and keyed by name and value alone, so any element's
 * surviving attribute could be spent as any other element's survivor.
 *
 * libxml normalizes an HTML boolean attribute to `name="name"`, so an authored
 * `disabled` and a renderer-GENERATED one are spelled identically. A task list
 * writes `disabled="disabled"` onto its own checkbox, and that vouched for the
 * authored `disabled` on a `<button>` elsewhere in the same document
 * (carve-php#1379). The button's attribute was gone from the emitted Carve
 * either way; what disappeared was the row saying so.
 *
 * SCOPE: the report only. Every assertion below pins the emitted Carve first,
 * so none of these tests can pass by the attribute quietly starting to survive.
 */
class ASurvivorAnswersForItsOwnElementTest extends TestCase
{
    /**
     * THE DEFECT: a second element in the document silenced the first's row.
     *
     * Asserted as a pair, because the bug is the DIFFERENCE between them. The
     * button alone always reported; it was the task list joining the document
     * that spent its survivor.
     */
    public function testAGeneratedBooleanDoesNotAnswerForAnAuthoredOne(): void
    {
        $alone = '<p><button disabled>x</button></p>';
        $withTaskList = '<p><button disabled>x</button></p><ul><li><input type="checkbox"> task</li></ul>';

        // The loss is the same in both, so the report has to be too.
        $this->assertStringNotContainsString('disabled', $this->carve($alone));
        $this->assertStringNotContainsString('disabled', $this->carve($withTaskList));

        $expected = [
            ['attribute-dropped', 'Dropped unsupported attribute disabled on <button>'],
            ['element-unwrapped', 'Replaced unsupported <button> element with Carve span metadata'],
        ];

        $this->assertSame($expected, $this->diagnostics($alone));
        $this->assertSame($expected, $this->diagnostics($withTaskList));
    }

    /**
     * Every boolean libxml normalizes collides the same way, so none of them
     * may be answered for by a task list's generated `disabled`.
     *
     * @param string $html
     * @param string $name
     * @param string $tag
     */
    #[DataProvider('booleanProvider')]
    public function testABooleanAttributeIsReportedDespiteATaskListInTheDocument(string $html, string $name, string $tag): void
    {
        $document = $html . '<ul><li><input type="checkbox"> task</li></ul>';

        $this->assertStringNotContainsString($name, $this->carve($document));
        $this->assertContains(
            ['attribute-dropped', 'Dropped unsupported attribute ' . $name . ' on <' . $tag . '>'],
            $this->diagnostics($document),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function booleanProvider(): array
    {
        return [
            'disabled on a button' => ['<p><button disabled>x</button></p>', 'disabled', 'button'],
            'multiple on a select' => ['<p><select multiple><option>o</option></select></p>', 'multiple', 'select'],
            'readonly on a textarea' => ['<p><textarea readonly>t</textarea></p>', 'readonly', 'textarea'],
        ];
    }

    /**
     * THE CONTROL, and the reason the scoping is by content rather than by tag.
     *
     * The task checkbox's own `type` and `checked` DID survive - the construct
     * they came back through is the one that element became. A fix that
     * refused every generated attribute would report these, and the report
     * would be wrong in the other direction.
     */
    public function testATaskCheckboxKeepsItsOwnAttributesUnreported(): void
    {
        $this->assertSame('- [x] task', $this->carve('<ul><li><input type="checkbox" checked> task</li></ul>'));
        $this->assertSame([], $this->diagnostics('<ul><li><input type="checkbox" checked> task</li></ul>'));

        $this->assertSame('- [ ] task', $this->carve('<ul><li><input type="checkbox"> task</li></ul>'));
        $this->assertSame([], $this->diagnostics('<ul><li><input type="checkbox"> task</li></ul>'));
    }

    /**
     * A ROUND TRIP MAY REWRITE CONTENT, so only the colliding class is scoped
     * by it.
     *
     * The `word` adapter moves a footnote definition out of its cell, and the
     * renderer writes its OWN backlink marker in place of the authored link
     * text: `<a href="#fnref1">back</a>` comes back as `↩`. That element's
     * `href` plainly survived while its words did not, so a report that asked
     * every attribute to match on content would call it dropped.
     */
    public function testAnAttributeSurvivesOnAnElementWhoseContentWasRewritten(): void
    {
        $html = '<table><tr><td>'
            . '<a href="#fn1" id="fnref1">1</a>'
            . '<div id="fn1"><blockquote cite="u"><p>q</p></blockquote><a href="#fnref1">back</a></div>'
            . '</td></tr></table>';

        $result = (new HtmlToCarve(importAdapter: 'word'))->convertWithReport($html);
        $rendered = (new CarveConverter())->convert($result->value);

        // The three that came back, on elements the round trip re-tagged or
        // re-worded. None of them may be reported.
        $this->assertStringContainsString('id="fn1"', $rendered);
        $this->assertStringContainsString('href="#fnref1"', $rendered);
        $this->assertStringContainsString('cite="u"', $rendered);

        $this->assertSame([], array_values(array_filter(
            $this->diagnostics($html, ['importAdapter' => 'word']),
            static fn (array $row): bool => $row[0] === 'attribute-dropped',
        )));
    }

    /**
     * THE TWO SIDES ARE NOT LAID OUT ALIKE, so content is compared by its
     * letters and digits.
     *
     * The input is the author's HTML and the emitted document is the
     * renderer's, which indents block children onto lines of their own: a
     * `<blockquote disabled><p>a</p><p>b</p></blockquote>` carries `ab` on the
     * way in and comes back with each paragraph on an indented line of its
     * own. Both are the same element, and
     * the `disabled` on it survived, so comparing the content verbatim would
     * report a loss that did not happen.
     *
     * @param string $html
     * @param string $rendered
     */
    #[DataProvider('reflowedProvider')]
    public function testASurvivingBooleanIsNotReportedWhenOnlyTheLayoutChanged(string $html, string $rendered): void
    {
        $this->assertStringContainsString($rendered, (new CarveConverter())->convert(
            (new HtmlToCarve())->convertWithReport($html)->value,
        ));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reflowedProvider(): array
    {
        return [
            'block children get lines of their own' => [
                '<blockquote disabled><p>a</p><p>b</p></blockquote>',
                '<blockquote disabled="disabled">',
            ],
            // `<address>` used to stand here. It no longer survives at all:
            // a sectioning or unmapped container is unwrapped rather than
            // written through a `::: name` fence, because that fence renders as
            // a `<div class="name">` and so put a class in the output the
            // document never carried (carve-php#1721). An admonition aside is
            // the fenced container that DOES come back as its own element, so
            // it is the one this row can ask the question of.
            'a container is written through a colon fence' => [
                '<aside class="admonition note" disabled><p>a</p></aside>',
                'disabled="disabled"',
            ],
        ];
    }

    /**
     * A DROPPED SUBTREE'S TEXT IS NOT PART OF THE CONTENT KEY.
     *
     * The emitted document cannot carry a `<script>`'s text, so counting it
     * would leave the input and output keys unable to ever meet. This
     * blockquote keeps its `disabled`, and the only row it may produce is the
     * one naming the script.
     */
    public function testTextTheImporterDropsDoesNotChangeTheElementsIdentity(): void
    {
        $html = '<blockquote disabled><script>bad</script><p>good</p></blockquote>';

        $this->assertStringContainsString('disabled="disabled"', (new CarveConverter())->convert(
            (new HtmlToCarve())->convertWithReport($html)->value,
        ));
        $this->assertSame(
            [['element-dropped', 'Dropped active <script> element']],
            $this->diagnostics($html),
        );
    }

    /**
     * A mapping may spell marks of its own around the content it keeps.
     *
     * `<q cite="u">` comes back as `<span cite="u">"quoted"</span>`, so the
     * element carries two more characters than it was given. The content key
     * keeps only letters and digits for exactly this reason.
     */
    public function testAQuoteMappingKeepsItsCiteUnreported(): void
    {
        $html = '<q cite="u">quoted</q>';

        $this->assertStringContainsString('cite="u"', (new CarveConverter())->convert(
            (new HtmlToCarve())->convertWithReport($html)->value,
        ));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * Two elements authoring the same boolean each get their own answer.
     *
     * Neither survives here, so both report - the pooled budget would have let
     * one stand in for the other had either come back.
     */
    public function testTwoAuthoredBooleansOnDifferentElementsBothReport(): void
    {
        $html = '<p><button disabled>a</button><select disabled><option>b</option></select></p>';

        $this->assertStringNotContainsString('disabled', $this->carve($html));
        $this->assertSame(
            [
                'Dropped unsupported attribute disabled on <button>',
                'Dropped unsupported attribute disabled on <select>',
            ],
            array_values(array_map(
                static fn (array $row): string => $row[1],
                array_filter($this->diagnostics($html), static fn (array $row): bool => $row[0] === 'attribute-dropped'),
            )),
        );
    }

    /**
     * @param string $html
     * @param array<string, mixed> $options
     *
     * @return list<array{string, string}>
     */
    private function diagnostics(string $html, array $options = []): array
    {
        $result = (new HtmlToCarve(...$options))->convertWithReport($html);

        return array_map(
            static fn (object $diagnostic): array => [$diagnostic->toArray()['code'], $diagnostic->toArray()['message']],
            $result->diagnostics,
        );
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }
}
