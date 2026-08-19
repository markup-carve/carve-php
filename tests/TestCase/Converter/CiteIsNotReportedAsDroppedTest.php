<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The import report does not announce a loss that did not happen.
 *
 * `<blockquote cite="u">` is KEPT on import - the ruling on
 * markup-carve/carve#1286 - and comes back as `{cite=u}`. The same run also
 * emitted `attribute-dropped` for it, because the converter's keep rule and the
 * diagnostic's represented-attribute list were two different lists and only the
 * first one had `cite` (carve-php#1337).
 *
 * WHY A FALSE POSITIVE HERE IS WORSE THAN SILENCE. An unreported loss costs a
 * reader one attribute. A reported non-loss costs them the report: once a
 * consumer finds a row describing an attribute that is plainly still in the
 * output, every OTHER `attribute-dropped` row carries less weight - including
 * the ones that are real, like the event handler asserted below. That is the
 * defect, and it is why this is a plain bug rather than a preference.
 *
 * carve-js stopped reporting it for the same reason once it kept the value
 * (carve-js#1125).
 *
 * SCOPE. This fixes the FIRST half of carve-php#1337 only. The second half -
 * that carve-php keeps EVERY unknown attribute, on a blockquote and on other
 * elements too, so `<blockquote foo="bar">` survives as `{foo=bar}` - is an open
 * question nobody has ruled, and this test deliberately pins it UNCHANGED rather
 * than resolving it in either direction. See
 * {@see self::testAnUnruledUnknownAttributeIsLeftExactlyAsItWas()}.
 */
class CiteIsNotReportedAsDroppedTest extends TestCase
{
    /**
     * Every diagnostic as `[code, severity, message]`.
     *
     * Read off the TYPED `diagnostics` property rather than the `report()`
     * array, so a changed code or severity is a compile-time visible difference
     * here rather than an array key that quietly stops existing.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function diagnostics(string $html): array
    {
        $rows = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $rows[] = [$diagnostic->code, $diagnostic->severity, $diagnostic->message];
        }

        return $rows;
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }

    /**
     * `[carve, wasReported]` with the list-table serializer switched on.
     *
     * @return array{0: string, 1: bool}
     */
    private function listTableImport(string $html): array
    {
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);

        return [trim($result->value), $result->diagnostics !== []];
    }

    /**
     * Both halves of the contradiction, asserted in one test.
     *
     * Either assertion alone is satisfiable by the wrong fix: silencing the
     * diagnostic while ALSO dropping the attribute would pass a check that only
     * looked at the report, and the ticket is precisely about the two
     * disagreeing. So the kept value and the empty report are asserted together.
     */
    public function testCiteIsKeptAndNotReportedAsDropped(): void
    {
        $html = '<blockquote cite="u"><p>q</p></blockquote>';

        // GLUED to the quote, with no blank line between: the shared
        // cross-engine fixture `html-import/blockquote-cite` arrived with the
        // corpus bump to carve b6917ab and pins that spelling.
        $this->assertSame("{cite=u}\n> q", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * The control that keeps the fix from becoming "stop reporting anything".
     *
     * A genuinely unsupported attribute is still reported, at the same code and
     * severity as before. `<hr>` is the element to ask, because it emits no
     * attribute block at all - so `foo` really is gone from the output here, and
     * the diagnostic about it is true.
     */
    public function testAGenuinelyDroppedAttributeIsStillReported(): void
    {
        $html = '<hr foo="bar">';

        $this->assertSame('---', $this->carve($html));
        $this->assertSame(
            [['attribute-dropped', 'info', 'Dropped unsupported attribute foo on <hr>']],
            $this->diagnostics($html),
        );
    }

    /**
     * An event handler is still dropped AND still reported, on this very element.
     *
     * The narrowing is per attribute NAME, not per element: adding `cite` to the
     * blockquote's represented set must not make the blockquote a place where
     * attributes stop being inspected. This is the row that would go red if the
     * fix had been written as "skip the attribute walk for blockquote".
     */
    public function testAnEventHandlerOnTheSameElementIsStillDroppedAndReported(): void
    {
        $html = '<blockquote cite="u" onclick="evil()"><p>q</p></blockquote>';

        $carve = $this->carve($html);
        $this->assertStringContainsString('cite=u', $carve);
        $this->assertStringNotContainsString('onclick', $carve);
        $this->assertSame(
            [['attribute-dropped', 'warning', 'Dropped event-handler attribute onclick on <blockquote>']],
            $this->diagnostics($html),
        );
    }

    /**
     * THE QUESTION IS RULED, and only the report moved.
     *
     * This test used to record the contradiction: `foo` survived as `{foo=bar}`
     * AND was reported as dropped. The ruling on markup-carve/carve-php#1337
     * keeps the retention exactly as it is - the ticket's premise that this
     * engine is a blanket passthrough is wrong, since a handler is stripped
     * from the same element - and fixes only the row.
     *
     * The `carve` assertion is deliberately unchanged from the version that
     * recorded the contradiction, so this test proves the retention did not
     * move while the row went away.
     */
    public function testAKeptUnknownAttributeIsNoLongerReportedAsDropped(): void
    {
        $html = '<blockquote foo="bar"><p>q</p></blockquote>';

        $this->assertSame("{foo=bar}\n> q", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * The same for `aria-*`, which is the other half of the real divergence.
     *
     * carve-js and carve-rs drop both of these; this engine keeps both. That
     * loss is filed against the siblings as markup-carve/carve-js#1156 and
     * markup-carve/carve-rs#1060 and is not what this changes - the row is.
     */
    public function testAKeptAriaAttributeIsNoLongerReportedAsDropped(): void
    {
        $html = '<blockquote aria-label="note"><p>q</p></blockquote>';

        $this->assertSame("{aria-label=note}\n> q", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * INSIDE A TABLE CELL the attribute really is dropped, so it is reported.
     *
     * Representation is a property of the POSITION as well as the tag/name
     * pair. `formatBlockAttributes()` returns an empty string while the
     * serializer is inside a cell, because a cell has no line for a block
     * attribute block to sit on (carve-php#1164) - so the quote comes back
     * without its source URL, and here the `attribute-dropped` row is TRUE.
     *
     * Answering the represented question on the tag/name pair alone traded one
     * false report for another: the report stopped lying about the common case
     * and started staying silent about a real loss in this one. Both halves are
     * asserted in this class so neither can be fixed by breaking the other.
     *
     * @return array<string, array{0: string}>
     */
    public static function tableCellProvider(): array
    {
        return [
            'in a td' => ['<table><tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr></table>'],
            'in a th' => ['<table><tr><th><blockquote cite="u"><p>q</p></blockquote></th></tr></table>'],
            // Not a direct child: the question is the ancestry, not the parent.
            'nested deeper inside a td' => [
                '<table><tr><td><div><blockquote cite="u"><p>q</p></blockquote></div></td></tr></table>',
            ],
        ];
    }

    #[DataProvider('tableCellProvider')]
    public function testCiteInsideATableCellIsDroppedAndSaidSo(string $html): void
    {
        // The loss is asserted first: without it this test would still pass if
        // the cell learned to carry the attribute, and would then be demanding
        // a diagnostic for something no longer lost.
        $this->assertStringNotContainsString('cite=u', $this->carve($html));
        $this->assertSame(
            [['attribute-dropped', 'info', 'Dropped unsupported attribute cite on <blockquote>']],
            $this->diagnostics($html),
        );
    }

    /**
     * A list item is NOT a table cell, so the exemption still applies there.
     *
     * The control against fixing the cell case by reporting everywhere a
     * blockquote is nested. A list item CAN carry the attribute block, and does.
     */
    public function testCiteInsideAListItemIsStillKeptAndSilent(): void
    {
        $html = '<ul><li><blockquote cite="u"><p>q</p></blockquote></li></ul>';

        $this->assertStringContainsString('cite=u', $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * THE ROUTE decides, not the ancestry - the list-table serializer keeps it.
     *
     * With `listTableForBlockCells` on, `processTable()` sends a table with
     * block-content cells to `processTableAsListTable()`, whose items are real
     * block context. The attribute block is written there and reads back as
     * `<blockquote cite="u">`, so nothing is lost and nothing may be reported.
     *
     * This is why the represented question asks the converter's OWN route
     * condition rather than "is there a `<td>` above me". An ancestry test is
     * right about the default pipe table and wrong about every document that
     * enables the opt-in - it would report a loss that the round trip disproves.
     */
    public function testTheListTableRouteKeepsCiteAndIsSilent(): void
    {
        [$carve, $reported] = $this->listTableImport(
            '<table><tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr></table>',
        );

        $this->assertStringContainsString('list-table', $carve);
        $this->assertStringContainsString('{cite=u}', $carve);
        $this->assertFalse($reported, 'the list-table route keeps cite, so nothing is dropped');
    }

    /**
     * The invariant both halves of this class are really asserting.
     *
     * A report is honest exactly when "was it reported" matches "did the round
     * trip lose it". Asserting the two together, over the routes that disagree,
     * is stronger than asserting either side's expected string: it fails for a
     * false positive AND for a false negative, without either test needing to
     * know which way the converter happens to answer today.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function routeProvider(): array
    {
        $inCell = '<table><tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr></table>';

        return [
            'a pipe row drops it' => [$inCell, false],
            'a list-table item keeps it' => [$inCell, true],
            'a plain quote keeps it' => ['<blockquote cite="u"><p>q</p></blockquote>', false],
            'a list item keeps it' => ['<ul><li><blockquote cite="u"><p>q</p></blockquote></li></ul>', false],
            // A CAPTION LOSES IT, and the round trip is what says so. The
            // importer writes the attribute block through the caption-line
            // slot, which carries inline content only, so the source reads
            // `^ {cite=u}` and renders `<caption>{cite=u}</caption>` with the
            // quote's attribute gone. Reading only the CARVE would call this
            // preserved, because the characters `cite=u` are present - they are
            // just text. The round trip is the honest oracle, which is why this
            // test renders rather than grepping the source.
            //
            // The mangling predates this predicate and is not fixed here; what
            // is asserted is only that a real loss is reported.
            'a top-level caption loses it' => [
                '<table><caption><blockquote cite="u"><p>q</p></blockquote></caption>'
                    . '<tr><td>x</td></tr></table>',
                false,
            ],
            'a nested caption loses it too' => [
                '<table><tr><td><table><caption><blockquote cite="u"><p>q</p></blockquote>'
                    . '</caption><tr><td>x</td></tr></table></td></tr></table>',
                true,
            ],
            // A cell with no owning table is never reached by `processTable()`,
            // so nothing raises the cell depth and the attribute block is
            // written normally. Both flag states, because the route never even
            // gets to be consulted here.
            'a td fragment with no table keeps it' => ['<td><blockquote cite="u"><p>q</p></blockquote></td>', false],
            'a td fragment with no table keeps it, opt-in on' => [
                '<td><blockquote cite="u"><p>q</p></blockquote></td>',
                true,
            ],
            // The nearest table is the INNER one here and it is a cell, not a
            // caption - the row that keeps the cell walk honest next to the
            // caption rows above.
            'a cell in a nested table keeps it' => [
                '<table><tr><td><table><tr><td><blockquote cite="u"><p>q</p></blockquote>'
                    . '</td></tr></table></td></tr></table>',
                true,
            ],
            // A FIGURE'S CAPTION is the same inline slot a table's caption is,
            // reached by a different method. `processFigure()` writes the
            // caption line, so `{cite=u}` lands as caption TEXT and renders
            // `<figcaption>{cite=u}</figcaption>` with no attribute on
            // anything. The old predicate walked for an enclosing `<caption>`
            // and a `<figcaption>` is not one, so this was a FALSE NEGATIVE on
            // `main`: a real loss, reported by nobody.
            //
            // It is also the row that the withdrawn carve-php#1347 tried to
            // reach with an element-keyed `figcaption` arm, and could not:
            // `processFigure()` has two paths, and an arm that fixed this row
            // broke two others. Nothing here names a figure.
            'a figure caption loses it' => [
                '<figure><img src="i.png"><figcaption><blockquote cite="u"><p>q</p></blockquote>'
                    . '</figcaption></figure>',
                false,
            ],
        ];
    }

    /**
     * The three shapes that need a converter option to exist at all.
     *
     * Each one is a serializer BYPASS: the node the walk is looking at is not
     * the node the serializer writes, and no amount of reading the input DOM
     * can tell. All three were false positives on `main`.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function bypassProvider(): array
    {
        $inCell = '<table><tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr></table>';

        // A footnote definition inside a cell, in the shape these adapters
        // emit: a reference and a definition pointing at each other.
        $footnoteInCell = '<table><tr><td>'
            . '<a href="#fn1" id="fnref1">1</a>'
            . '<div id="fn1"><blockquote cite="u"><p>q</p></blockquote><a href="#fnref1">back</a></div>'
            . '</td></tr></table>';

        return [
            // The stored source is emitted VERBATIM and its descendants are
            // never converted - so the cell below never runs, and the `cite`
            // the prediction watched being dropped by a pipe row comes back
            // untouched from the stored text.
            'trusted round-trip emits stored source' => [
                '<div data-djot-src="{cite=u}&#10;&#10;&gt; q">' . $inCell . '</div>',
                ['trustedRoundTrip' => true],
            ],
            // These adapters MOVE footnote definitions out of their cell before
            // serialization, so the quote is never written through a pipe row
            // at all. The input DOM says "inside a td" right up until it isn't.
            'the word adapter moves the definition out' => [$footnoteInCell, ['importAdapter' => 'word']],
            'the google-docs adapter moves it out too' => [$footnoteInCell, ['importAdapter' => 'google-docs']],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testTheReportAgreesWithTheRoundTrip(string $html, bool $listTable): void
    {
        $this->assertReportMatchesRoundTrip($html, ['listTableForBlockCells' => $listTable]);
    }

    /**
     * @param string $html
     * @param array<string, mixed> $options
     */
    #[DataProvider('bypassProvider')]
    public function testTheReportAgreesWithTheRoundTripThroughABypass(string $html, array $options): void
    {
        $this->assertReportMatchesRoundTrip($html, $options);
    }

    /**
     * THE INVARIANT: reported exactly when the round trip loses it.
     *
     * RENDERS rather than grepping the Carve, deliberately. Characters can
     * survive into a slot that cannot hold their meaning - a cited quote in a
     * caption writes `^ {cite=u}`, where the characters `cite=u` are present
     * and are caption TEXT. Grepping the source calls that preserved; rendering
     * it shows `<caption>{cite=u}</caption>` with the attribute gone, which is
     * the truth. This is the difference the whole redesign turns on.
     *
     * @param string $html
     * @param array<string, mixed> $options
     */
    private function assertReportMatchesRoundTrip(string $html, array $options): void
    {
        $result = (new HtmlToCarve(...$options))->convertWithReport($html);
        $survived = str_contains((new CarveConverter())->convert($result->value), 'cite="u"');
        $reported = $result->diagnostics !== [];

        $this->assertSame(
            !$survived,
            $reported,
            $survived
                ? 'cite survived the round trip, so no attribute-dropped may be reported'
                : 'cite was lost, so attribute-dropped must be reported',
        );
    }

    /**
     * The same blind spot for `id` and `class` - CLOSED, and not one at a time.
     *
     * These are represented unconditionally and have been since long before
     * `cite` joined them, so a blockquote's `id`/`class` inside a cell used to
     * be dropped SILENTLY. That was recorded here as a known gap, deliberately
     * left alone, because closing it under the old predicate meant teaching
     * that predicate about `id` and `class` in a cell - a third and fourth arm
     * on a list that already had to be maintained by hand.
     *
     * The redesign closes it without naming them (carve-php#1346). Nothing in
     * the diagnostic mentions `id`, `class`, or a cell: the rule asks the
     * emitted document whether the value came back in an attribute position,
     * and here it did not. Every represented name gets the same answer for
     * free, which is the difference between a fix and an enumeration.
     */
    public function testTheSameGapForIdAndClassIsClosedByTheSameRule(): void
    {
        $html = '<table><tr><td><blockquote id="i" class="x"><p>q</p></blockquote></td></tr></table>';

        $this->assertStringNotContainsString('#i', $this->carve($html));
        $this->assertStringNotContainsString('.x', $this->carve($html));
        $this->assertSame(
            [
                ['attribute-dropped', 'info', 'Dropped unsupported attribute id on <blockquote>'],
                ['attribute-dropped', 'info', 'Dropped unsupported attribute class on <blockquote>'],
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * The emitted document is rendered ONCE, so the walk stays linear.
     *
     * The observational rule renders the emitted Carve back to HTML to see what
     * survived. Doing that per attribute would cost O(attributes x document) -
     * far worse than the per-table rescan it replaced - so the tally is built
     * once per conversion and consumed as a budget. Nothing else bounds it:
     * these attributes are represented, so they emit no diagnostics and
     * `maxDiagnostics` never trips.
     *
     * Asserted as a RATIO against the engine's own smaller run rather than a
     * wall-clock threshold, so a slow or loaded machine cannot fail it. Doubling
     * the rows roughly doubles the work when the tally is built once; rendering
     * per attribute makes the same step superlinear.
     */
    public function testTheEmittedDocumentIsRenderedOncePerConversion(): void
    {
        $time = static function (int $rows): float {
            $body = str_repeat('<tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr>', $rows);
            $start = microtime(true);
            (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport('<table>' . $body . '</table>');

            return microtime(true) - $start;
        };

        // Warm the autoloader and JIT-ish caches so the first sample is not the
        // one that pays for them.
        $time(50);

        // THE BEST OF THREE ROUNDS, not one sample of each size. A single pair
        // is a coin flip on a shared runner: a `small` that gets descheduled or
        // a `large` that does inflates the ratio on its own, and this guard
        // failed twice at 2.8 and 3.5 on unrelated pull requests while the same
        // code measured 1.7 to 2.1 locally under the same coverage extension.
        // Every other ratio guard in this suite already takes the best of
        // several runs; a flaky guard costs a CI round trip each time and
        // teaches the next reader to re-run it rather than read it.
        $ratio = INF;
        for ($round = 0; $round < 3; $round++) {
            $small = $time(200);
            $large = $time(400);
            $ratio = min($ratio, $large / max($small, 1.0e-6));
        }

        $this->assertLessThan(
            2.6,
            $ratio,
            'doubling the rows should roughly double the work; a super-linear ratio means the per-table route memo is gone',
        );
    }

    /**
     * The observed output does not OUTLIVE the inspection that read it.
     *
     * Both the emitted Carve and the tally built from it describe ONE
     * conversion. A converter is reusable and long-lived by design, so a value
     * left standing would let a later walk answer from the previous document -
     * the worst kind of wrong report, because it is right often enough not to
     * be noticed. Both are cleared in a `finally`, so a throwing walk cannot
     * leave one behind either.
     */
    public function testTheObservedOutputDoesNotOutliveTheInspection(): void
    {
        $converter = new HtmlToCarve();
        $converter->convertWithReport(
            '<table><tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr></table>',
        );

        foreach (['inspectedCarve', 'survivingImportAttributes'] as $name) {
            $this->assertNull(
                (new ReflectionProperty(HtmlToCarve::class, $name))->getValue($converter),
                $name . ' must not survive the walk that populated it',
            );
        }
    }

    /**
     * `cite` on another element is decided the same way: by asking.
     *
     * This used to assert the opposite - that `<q cite="u">` keeps reporting,
     * because the represented pair was tag AND name and only `blockquote`
     * carried `cite`. It was asserting a FALSE row: the attribute round-trips
     * as `["x"]{cite="u"}` and comes back on the rendered `<span cite="u">`,
     * so nothing was lost. The tag/name pair was the enumeration talking.
     *
     * The claim it was really protecting - that the fix must not be written
     * against the attribute name alone - now holds structurally, because no
     * name is consulted at all.
     */
    public function testCiteOnAnotherElementIsDecidedByTheDocumentToo(): void
    {
        $carve = $this->carve('<p><q cite="u">x</q></p>');

        $this->assertStringContainsString('cite="u"', $carve);
        $this->assertStringContainsString(
            'cite="u"',
            (new CarveConverter())->convert($carve),
            'the attribute comes back on the rendered element, so nothing was dropped',
        );
        $this->assertSame([], $this->diagnostics('<p><q cite="u">x</q></p>'));
    }

    /**
     * THE CHECKABLE CLAIM: no attribute NAME decides the report.
     *
     * The enumeration this replaced is gone from the class entirely, so a
     * future reader cannot edit a name list believing it still steers the
     * diagnostic. `importAttributeIsReadNotWritten()` remains and is a name
     * question by design - it asks HOW an attribute is represented, for the
     * families whose meaning never enters an attribute position at all, which
     * is the one question the output cannot answer.
     */
    public function testNoNameEnumerationDecidesTheReport(): void
    {
        $this->assertFalse(
            method_exists(HtmlToCarve::class, 'isRepresentedImportAttribute'),
            'the represented-name enumeration is a second copy of the strip policy',
        );
    }

    /**
     * THE ONE ROW THIS ALSO REMOVED, recorded rather than discovered later.
     *
     * A boolean attribute carries an EMPTY value, and `importAttributeSurvived()`
     * has always vouched for an empty value outright - "an empty value carried
     * no information for the round trip to drop", the rule the shared fixture
     * `html-import/semantic-span-attributes` pins across the three engines.
     * Where the conversion discards the whole attribute block, as an `<input>`
     * becoming a task marker does, that rule now decides alone: the row used to
     * come from the name enumeration in front of it.
     *
     * Restoring the row would mean knowing that `open` is represented on
     * `<details>` and not on `<input>` - which is the enumeration again. Over
     * 495 tag/attribute pairs these four - `open` and `hidden` on `<input>` and
     * on `<math>` - are the only rows removed whose attribute is absent from
     * the emitted document, and none of them is valid HTML for its element.
     *
     * THE ATTRIBUTE ROW STAYS GONE and the ELEMENT now says so instead. The
     * `<input>` is discarded here, and reporting that covers every attribute
     * on it without anything having to know which ones were representable
     * (carve-php#1377). That is the row this test was really missing: the
     * silence it recorded was the whole element leaving without a word.
     */
    public function testABooleanAttributeOnAnElementThatDiscardsItIsSilent(): void
    {
        $this->assertSame('- t', $this->carve('<ul><li><input open> t</li></ul>'));
        $this->assertSame(
            [['element-dropped', 'warning', 'Dropped unsupported <input> element']],
            $this->diagnostics('<ul><li><input open> t</li></ul>'),
        );
    }

    /**
     * An attribute only the RENDERER strips is still reported.
     *
     * `srcdoc` and `formaction` are kept by this importer and blanked on the
     * way out by PART 9 §25's defenses, so the two sides disagree about them -
     * and asking the rendered document gets it right without either side
     * having to know, which is the property a name list cannot have.
     */
    public function testAnAttributeTheRendererStripsIsReported(): void
    {
        $codes = array_column($this->diagnostics('<p srcdoc="&lt;script&gt;">t</p>'), 0);

        $this->assertContains('attribute-dropped', $codes);
    }
}
