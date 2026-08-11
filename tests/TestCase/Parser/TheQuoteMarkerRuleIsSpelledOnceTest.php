<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\ContainerPrefix;
use PHPUnit\Framework\TestCase;

/**
 * The block-quote marker rule is asked of `ContainerPrefix`, everywhere.
 *
 * carve-php#966 took the count of open-coded spellings from 14 to 2.
 * markup-carve/carve-php#969 filed the 2, and a sweep for `'>'` byte tests
 * across `src/` found a THIRD the ticket had not counted, in
 * `ReferenceDefinitionExtractor`. All three ask `ContainerPrefix` now.
 *
 * A COLLAPSE IS BEHAVIOR-PRESERVING, so an A/B against `main` cannot fail on
 * its own, and this file would prove nothing if it stopped at "the output is
 * the same". Two of the three copies were NOT behavior-preserving, because they
 * were looser than the rule the parser applies two functions away - and in both
 * shapes the parser BUILDS a paragraph while the copy reported none. Those are
 * the rows below; the third copy is behavior-identical by construction and is
 * pinned by a named mutation instead.
 *
 * WHAT THE FOURTH COPY WAS FOR. `BlockParser::isEmptyQuoteLine()` asks about
 * EMPTINESS, not about prefix stripping - "does this line hold a paragraph a
 * dedented line could fold into?" (PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE).
 * That is a genuinely different question, so the function stays; what it
 * borrowed and got wrong was the marker walk inside it.
 *
 * THE FUNCTION IS LOAD-BEARING AND ITS COPY WAS NOT. Instrumented across the
 * suite it is called 110,514 times and answers true 92 times, and every one of
 * those 92 inputs is `>` or `> >` - two shapes the collapsed rule decides
 * identically. Forcing it to false fails 9 tests and forcing it to true fails
 * 91, so the function is covered; but replacing its walk with the helper's,
 * dropping its `trim()`, dropping its inter-marker `ltrim()` and even dropping
 * its `$sawQuote` guard each left all 9,062 tests green. The differences
 * between the copy and the rule were unobserved, which is why the two shapes
 * below get fixtures.
 *
 * TWO MUTATIONS HERE DO NOT DISCRIMINATE, and both are diagnoses rather than
 * gaps:
 *
 *  - Walking the LOOSE rule instead of the strict one leaves every row green.
 *    The two disagree on exactly one shape, `>text` (see `ContainerPrefix`), and
 *    a line with text after the marker is never empty under either - so which
 *    rule answers this question is provably immaterial.
 *  - Dropping the `ReferenceDefinitionExtractor` dedent gate leaves every row
 *    green. Its job is ORDER - dedent to the item's content column before
 *    reading the marker - and for a line without one the unconditional dedent
 *    further down does the same thing. The site is behavior-neutral and is
 *    changed only so no marker byte test is spelled outside `ContainerPrefix`.
 */
class TheQuoteMarkerRuleIsSpelledOnceTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * Two spaces between the markers is ONE marker and the content ` >`.
     *
     * The copy `ltrim`ed between markers, so it read `><SP><SP>>` as two markers and
     * an empty tail - "no paragraph here" - while the parser read one marker
     * and built `<blockquote><p>&gt;</p></blockquote>`. The dedented line then
     * had a paragraph to fold into and did not fold into it.
     */
    public function testTwoSpacesBetweenMarkersIsOneMarkerAndContent(): void
    {
        $html = $this->html("- a\n\n  >  >\ndedent\n");

        // The paragraph the parser builds is the one the tracker must see.
        $this->assertStringContainsString('<p>&gt;', $html, $html);
        $this->assertStringContainsString("&gt;\ndedent</p>", $html, $html);
    }

    /**
     * The lone-marker shapes are unchanged, which is what makes the row above a
     * fix rather than a removal.
     *
     * `> >` really does hold no paragraph, so the dedented line still does not
     * fold - and neither does the same line with trailing spaces or a trailing
     * tab, because a blank line holds spaces and tabs (carve-php#967).
     */
    public function testAMarkerWithNothingAfterItStillHoldsNoParagraph(): void
    {
        foreach (["  > >\n", "  > > \n", "  > >\t\n", "  >\n", "  >   \n"] as $middle) {
            $html = $this->html("- a\n\n{$middle}dedent\n");

            $this->assertStringContainsString('<p>dedent</p>', $html, $middle . ' -> ' . $html);
        }
    }

    /**
     * A vertical tab after the marker is CONTENT, exactly as a form feed is.
     *
     * The copy's `trim()` ran on PHP's default charlist, `" \t\n\r\0\x0B"`,
     * which holds a vertical tab and does not hold a form feed. So two lines of
     * the same shape got opposite answers - `> <FF>` folded and `> <VT>` did
     * not - decided by a charlist rather than by a rule, while the parser built
     * a paragraph for both. A blank line holds spaces and tabs and nothing else
     * (carve-php#967), so neither character is padding here.
     */
    public function testAVerticalTabAfterTheMarkerAgreesWithAFormFeed(): void
    {
        $vertical = $this->html("- a\n\n  > \v\ndedent\n");
        $form = $this->html("- a\n\n  > \f\ndedent\n");

        $this->assertStringContainsString("\ndedent</p>", $vertical, $vertical);
        $this->assertStringContainsString("\ndedent</p>", $form, $form);
    }

    /**
     * A marker with real content still holds a paragraph, at any depth.
     */
    public function testAMarkerWithContentStillFolds(): void
    {
        $html = $this->html("- a\n\n  > > q\ndedent\n");

        $this->assertStringContainsString("q\ndedent", $html, $html);
    }

    /**
     * The emptiness question itself, asked directly.
     *
     * The rows above reach `isEmptyQuoteLine()` through the lazy-continuation
     * tracker, which never hands it a line without a marker - so the guard that
     * answers false for one could be deleted with the whole suite still green,
     * exactly as the `$sawQuote` guard it replaced could. That is a check that
     * cannot fail from the outside, and the method is `protected` rather than
     * private, so its contract is asserted here instead of left to a caller
     * that happens not to exercise it.
     */
    public function testTheEmptinessQuestionAnsweredDirectly(): void
    {
        $parser = new class extends BlockParser {
            public function emptyQuote(string $line): bool
            {
                return $this->isEmptyQuoteLine($line);
            }
        };

        // Written as concatenations so the code sniffer cannot rewrite a
        // control character into the spaces that would silently change what
        // the row tests - it did exactly that to the tab rows once.
        $tab = "\t";
        $verticalTab = "\v";
        $formFeed = "\f";

        // A marker and nothing else, at any depth, with blank-line padding.
        foreach (['>', '> ', '> >', '> > ', '>   ', '> >' . $tab, '  > >  '] as $line) {
            $this->assertTrue($parser->emptyQuote($line), json_encode($line));
        }

        // No marker at all - including a blank line, which is what the guard is
        // for: without it `stripQuoteMarkers('')` returns `''` and a blank line
        // reads as a quote holding nothing.
        foreach (['', '   ', $tab, 'q', '>text'] as $line) {
            $this->assertFalse($parser->emptyQuote($line), json_encode($line));
        }

        // A marker with content, by the LANGUAGE rule rather than by a looser
        // copy of it: `>  >` is one marker and the content ` >`, and a vertical
        // tab is content and not padding.
        foreach (['>  >', '> > q', '> ' . $verticalTab, '> ' . $formFeed, '>   >   '] as $line) {
            $this->assertFalse($parser->emptyQuote($line), json_encode($line));
        }
    }

    /**
     * The stages the fence scan needs, from the one rule.
     *
     * The scan wanted the line at EACH quote depth rather than the fully
     * stripped tail, and built that with its own `$line[0] === '>'` test in
     * front of the same `quoteContent()` call. The test was redundant -
     * `quoteContent()` already answers null for a line that does not start with
     * the marker - and redundant is how two spellings drift apart.
     */
    public function testQuoteStagesKeepsEachDepth(): void
    {
        $this->assertSame(['> > x', '> x', 'x'], ContainerPrefix::quoteStages('> > x'));
        $this->assertSame(['x'], ContainerPrefix::quoteStages('x'));
        // `>text` opens no quote (PART 9 §11), so there is no second stage.
        $this->assertSame(['>text'], ContainerPrefix::quoteStages('>text'));
        // A lone `>` is a marker whose content is empty.
        $this->assertSame(['>', ''], ContainerPrefix::quoteStages('>'));
        // The last stage is what `stripQuoteMarkers()` returns, always.
        foreach (['> > x', 'x', '>text', '>', '> >  y'] as $line) {
            $stages = ContainerPrefix::quoteStages($line);
            $this->assertSame(
                ContainerPrefix::stripQuoteMarkers($line),
                $stages[count($stages) - 1],
                $line,
            );
        }
    }

    /**
     * The fence scan's own caller, at the depths the stages exist for.
     *
     * A code fence nested at any quote depth has to be tracked, or the
     * footnote-looking line inside it is collected as a definition instead of
     * staying literal. This is what the stages are FOR, so a stages helper that
     * stopped after one marker would pass the unit rows above at depth 1 and
     * fail here.
     */
    public function testAFenceIsTrackedAtEveryQuoteDepth(): void
    {
        foreach ([1, 2, 3] as $depth) {
            $prefix = str_repeat('> ', $depth);
            $html = $this->html("{$prefix}```\n{$prefix}[^f]: not a footnote\n{$prefix}```\n");

            $this->assertStringContainsString('[^f]: not a footnote', $html, "depth {$depth}: " . $html);
            $this->assertStringNotContainsString('footnote-ref', $html, "depth {$depth}: " . $html);
            $this->assertStringContainsString('<pre><code>', $html, "depth {$depth}: " . $html);
        }
    }

    /**
     * The third site: a reference definition at an item's content column.
     *
     * `ReferenceDefinitionExtractor` decided whether to take the dedent with its
     * own `$atColumn[0] === '>'` test, in front of the loose strip that follows
     * it. The two must admit the same lines - a `>text` dedented and then not
     * stripped would be read at the wrong column - so the test asks
     * `ContainerPrefix` now. Behavior-identical by construction; the mutation
     * that proves the site is live is in the PR, not here.
     */
    public function testAQuotedDefinitionAtAnItemsContentColumnStillResolves(): void
    {
        foreach (
            [
                'at the item content column' => "[x][r]\n\n- a\n\n  > [r]: /u\n",
                'on the marker line' => "[x][r]\n\n- a\n  > [r]: /u\n",
                'nested two deep' => "[x][r]\n\n- a\n\n  > > [r]: /u\n",
                'at the top level' => "[x][r]\n\n> [r]: /u\n",
            ] as $label => $source
        ) {
            $this->assertStringContainsString('href="/u"', $this->html($source), $label);
        }
    }
}
