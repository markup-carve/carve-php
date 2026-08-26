<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two import classes ruled on markup-carve/carve#1608 and landed as clauses in
 * `docs/html-import.md` by markup-carve/carve#1627 (markup-carve/carve-php#1629).
 *
 * **A declared loss is a ceiling, not a licence.** An import may lose what it
 * declares and no more. Carve has no spelling for an empty `<dd>` - six were
 * probed on the ticket and every one leaks a colon into the text, folds into
 * the term, or renders `&nbsp;` - and the bare colon line this engine wrote is
 * the worst of them, because the parser reads it as a continuation of the line
 * above. So the description was lost AND its neighbour damaged, which is the
 * loss the rule forbids. The description is dropped instead and the term stays.
 *
 * **An endnotes section keeps the position it was written at.** Definitions
 * collect to document level whatever the source says, so a section with content
 * after it re-rendered PAST that content: the same characters in the wrong
 * order, with nothing to say so. Carve spells the position, and a spelling the
 * language has is not a loss an import may take - so the import writes
 * `::: footnotes` where the section sat. Where the section is last it writes no
 * directive, because the definitions already render there.
 *
 * THE RENDER-BACK ASSERTION IS THE LOAD-BEARING ONE, in both halves. A source
 * shape can be pinned and still be wrong - `:: term` alone would satisfy a
 * string comparison whether or not the term survived a re-parse - so every case
 * here asserts what the imported source RENDERS, which is the property that was
 * false. The two source-shape assertions that remain are byte-exact against the
 * shared fixtures `html-import/empty-definition-description` and
 * `html-import/endnotes-section-not-last`, which the spec pin does not carry
 * yet.
 *
 * NO ROW IS OWED for an empty description: the sentinel spells it, so nothing
 * is lost and the ceiling has nothing to permit (markup-carve/carve#1827).
 */
class ADeclaredLossIsACeilingOnImportTest extends TestCase
{
    protected function html(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }

    protected function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * The empty description is SPELLED, so the round trip loses nothing.
     */
    public function testAnEmptyDescriptionIsWrittenWithTheSentinel(): void
    {
        $source = $this->import('<dl><dt>term</dt><dd></dd></dl>');

        $this->assertSame(":: term\n: {empty}\n", $source);
        $this->assertSame("<dl>\n  <dt>term</dt>\n  <dd></dd>\n</dl>\n", $this->html($source));
    }

    /**
     * The defect verbatim, as a render: the bare colon line was read as more of
     * the term, so the `<dt>` carried a colon the HTML never held.
     */
    public function testTheTermDoesNotCollectTheColonLine(): void
    {
        $rendered = $this->html($this->import('<dl><dt>term</dt><dd></dd></dl>'));

        $this->assertStringNotContainsString(':', $rendered);
        $this->assertStringContainsString('<dt>term</dt>', $rendered);
    }

    /**
     * AN ENTRY AFTER THE EMPTY ONE CHANGES NOTHING. Each entry writes its own
     * description line, so `t1` keeps its empty one and `d2` stays on `t2`: one
     * `<dl>`, no stray `<p>:</p>`, and no colon anywhere in the output.
     */
    public function testAnEmptyDescriptionKeepsTheListWhole(): void
    {
        $rendered = $this->html($this->import('<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>'));

        $this->assertSame(1, substr_count($rendered, '<dl>'), $rendered);
        $this->assertStringNotContainsString('<p>:</p>', $rendered);
        $this->assertStringContainsString('<dt>t1</dt>', $rendered);
        $this->assertStringContainsString('<dd>d2</dd>', $rendered);
        // The whole point: `t1` gains nothing it never had.
        $this->assertStringNotContainsString("<dt>t1</dt>\n  <dt>t2</dt>", $rendered);
        $this->assertStringContainsString("<dt>t1</dt>\n  <dd></dd>", $rendered);
    }

    /**
     * The damaged neighbour is whatever line is above, not only a `<dt>`: an
     * empty description after a filled one folded into the filled one.
     */
    public function testAnEmptyDescriptionDoesNotDamageTheDescriptionBeforeIt(): void
    {
        $rendered = $this->html($this->import('<dl><dt>t</dt><dd>a</dd><dd></dd></dl>'));

        $this->assertStringContainsString('<dd>a</dd>', $rendered);
        $this->assertStringNotContainsString(':', $rendered);
    }

    /**
     * THE NEAR MISS. `&nbsp;` is what a description "holding nothing" looks
     * like to a reader and it is NOT this case: it writes a colon, the canonical
     * separator, and the non-breaking space, which round-trips exactly. A fix
     * that asked whether the element
     * had children rather than whether it WRITES anything would take this one
     * too.
     */
    public function testANonBreakingSpaceDescriptionKeepsItsLine(): void
    {
        $source = $this->import('<dl><dt>term</dt><dd>&nbsp;</dd></dl>');

        $this->assertStringContainsString(": \u{00A0}", $source);
        $this->assertStringContainsString('<dd>&nbsp;</dd>', $this->html($source));
    }

    /**
     * An ordinary description is untouched, which is the whole population this
     * change must not reach.
     */
    public function testAnOrdinaryDescriptionIsUnchanged(): void
    {
        $source = $this->import('<dl><dt>term</dt><dd>d</dd></dl>');

        $this->assertSame(":: term\n: d\n", $source);
        $this->assertStringContainsString('<dd>d</dd>', $this->html($source));
    }

    /**
     * The shared fixture's source, byte for byte.
     */
    public function testAnEndnotesSectionThatIsNotLastKeepsItsPosition(): void
    {
        $source = $this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>' . "\n"
            . '<p>after</p>',
        );

        $this->assertSame("a[^1]\n\n::: footnotes\n\n:::\n\nafter\n\n[^1]: n\n", $source);
    }

    /**
     * The defect verbatim, as a render: the section came back after `after`.
     * The characters were all present and their order was not the input's.
     */
    public function testTheRenderedSectionComesBackBeforeTheContentThatFollowedIt(): void
    {
        $rendered = $this->html($this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>' . "\n"
            . '<p>after</p>',
        ));

        $section = strpos($rendered, 'role="doc-endnotes"');
        $after = strpos($rendered, '<p>after</p>');
        $this->assertIsInt($section, $rendered);
        $this->assertIsInt($after, $rendered);
        $this->assertLessThan($after, $section, $rendered);
    }

    /**
     * THE BOUND THE CLAUSE STATES: "Where the section IS last, the directive is
     * not written." The definitions already render there, so writing it would
     * put a construct in the source the input did not distinguish.
     */
    public function testAnEndnotesSectionThatIsLastWritesNoDirective(): void
    {
        $source = $this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>',
        );

        $this->assertStringNotContainsString(':::', $source);
        $this->assertSame("a[^1]\n\n[^1]: n\n", $source);
    }

    /**
     * Pretty-printed HTML puts a newline and often a comment between
     * `</section>` and the end of the document. Neither writes anything a
     * reader sees, so neither makes a last section un-last - a check that read
     * "has a next sibling" would call this one not-last and write a directive
     * the input did not distinguish.
     */
    public function testWhitespaceAndACommentAfterTheSectionStillCountAsLast(): void
    {
        $source = $this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>' . "\n"
            . '<!-- end -->' . "\n",
        );

        $this->assertStringNotContainsString(':::', $source);
    }

    /**
     * "Last" is decided by what the sibling WRITES, not by whether one exists.
     * An element that puts nothing in the source - a `<script>`, an empty
     * `<p>` - leaves the section last, so the directive stays unwritten; a
     * check that stopped at "there is a following element" wrote one for every
     * document whose body ends in a script tag, which is most of them.
     *
     * Asked through the same `writesNothing()` the backward walk uses, so the
     * two directions cannot disagree about what a silent element is.
     *
     * @return array<string, array{0: string}>
     */
    public static function silentTailProvider(): array
    {
        return [
            'a script' => ['<script>x</script>'],
            'a style' => ['<style>a{}</style>'],
            'an empty paragraph' => ['<p></p>'],
            'an empty div' => ['<div></div>'],
        ];
    }

    #[DataProvider('silentTailProvider')]
    public function testAnElementThatWritesNothingLeavesTheSectionLast(string $tail): void
    {
        $source = $this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>'
            . $tail,
        );

        $this->assertSame("a[^1]\n\n[^1]: n\n", $source);
    }

    /**
     * The opposite bound: reading only the section's own siblings would call a
     * wrapped section last and move its notes past the paragraph that follows
     * the wrapper.
     */
    public function testASectionWrappedInADivIsNotLastWhenContentFollowsTheWrapper(): void
    {
        $rendered = $this->html($this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<div><section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section></div>' . "\n"
            . '<p>after</p>',
        ));

        $section = strpos($rendered, 'role="doc-endnotes"');
        $after = strpos($rendered, '<p>after</p>');
        $this->assertIsInt($section, $rendered);
        $this->assertIsInt($after, $rendered);
        $this->assertLessThan($after, $section, $rendered);
    }

    /**
     * A section only PART of which rebuilt takes the remainder path, which this
     * change does not reach: the unreferenced note stays on the page as the
     * list it is (markup-carve/carve-php#1582), and no directive is written for
     * a section that did not wholly leave.
     */
    public function testAPartiallyRebuiltSectionIsUntouched(): void
    {
        $source = $this->import(
            '<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li>'
            . '<li id="fn2"><p>unreferenced</p></li></ol></section>' . "\n"
            . '<p>after</p>',
        );

        $this->assertStringNotContainsString('::: footnotes', $source);
        $this->assertStringContainsString('unreferenced', $source);
    }
}
