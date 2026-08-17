<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
     * THE OPEN QUESTION, pinned unchanged on purpose.
     *
     * carve-php keeps every unknown attribute, so `foo` survives as `{foo=bar}`
     * AND is reported as dropped - the same contradiction this PR fixes for
     * `cite`, one the ticket splits off as unruled because the fix could go
     * either way: narrow the keeping to the attributes the spec names, or widen
     * the spec and stop reporting. carve-js deliberately did not copy the
     * blanket keep.
     *
     * This test does not endorse the behavior. It records it, so that whichever
     * way markup-carve/carve-php#1337's second half is ruled, the change lands
     * on a red test instead of passing unnoticed - and so a reader does not
     * mistake the surviving contradiction for one this PR missed.
     */
    public function testAnUnruledUnknownAttributeIsLeftExactlyAsItWas(): void
    {
        $html = '<blockquote foo="bar"><p>q</p></blockquote>';

        $this->assertSame("{foo=bar}\n> q", $this->carve($html));
        $this->assertSame(
            [['attribute-dropped', 'info', 'Dropped unsupported attribute foo on <blockquote>']],
            $this->diagnostics($html),
        );
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
     * The represented predicate stays a TWO-ARGUMENT protected method.
     *
     * `HtmlToCarve` is not final and this method is not internal, so a
     * downstream subclass may override it. Adding a parameter - even an
     * optional one - makes such a subclass a fatal incompatible-signature
     * error at class-declaration time, which no test of behavior would catch
     * because the class never loads.
     *
     * The position question therefore lives in a separate helper rather than
     * as a third argument here. This test declares the override the old way and
     * fails to even load if that is undone.
     */
    public function testTheRepresentedPredicateKeepsItsOverridableSignature(): void
    {
        $subclass = new class extends HtmlToCarve {
            protected function isRepresentedImportAttribute(string $tag, string $name): bool
            {
                return parent::isRepresentedImportAttribute($tag, $name);
            }
        };

        $this->assertStringContainsString('cite=u', $subclass->convert('<blockquote cite="u"><p>q</p></blockquote>'));

        $method = new ReflectionMethod(HtmlToCarve::class, 'isRepresentedImportAttribute');
        $this->assertSame(2, $method->getNumberOfParameters());
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

        $small = $time(200);
        $large = $time(400);

        $this->assertLessThan(
            2.6,
            $large / max($small, 1.0e-6),
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
     * `cite` elsewhere is untouched: the represented pair is tag AND name.
     *
     * `<q cite="u">` is a different element with a different conversion, so
     * adding the blockquote pair must not silence it. Without this, the fix
     * could have been written against the attribute name alone.
     */
    public function testCiteOnAnotherElementIsUnaffected(): void
    {
        $codes = array_column($this->diagnostics('<p><q cite="u">x</q></p>'), 0);

        $this->assertContains('attribute-dropped', $codes);
    }
}
