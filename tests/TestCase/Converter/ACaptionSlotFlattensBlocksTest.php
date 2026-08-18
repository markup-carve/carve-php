<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block content in a caption is flattened deliberately.
 *
 * A caption line is an INLINE slot - `^ ` followed by one line of inline
 * content - so a block cannot live in one. This importer used to write the
 * block's Carve SOURCE into that slot, where it re-parses as prose: a
 * `<ul><li>a</li><li>b</li></ul>` came back as the literal characters `- a`
 * and `- b`, so the document GAINED text the author never wrote and LOST the
 * list they did write (carve-php#1345).
 *
 * Of the two honest outcomes the ticket names - flatten it, or refuse the
 * caption and keep the blocks elsewhere - this takes the first, because
 * carve-js and carve-rs both already do: each renders `^ a b` for the list
 * above. carve-php was the outlier, so this is a convergence rather than a new
 * rule, and no ruling was needed to pick.
 *
 * THE DIAGNOSTIC IS NOT PART OF THIS. Both sibling engines also emit an
 * `element-unwrapped` row per block they unwrap, and carve-php does not yet.
 * Reporting it needs to know WHICH caption the serializer consumed, and the
 * conversion and the inspection walk parse separate DOMDocuments that the
 * adapters then mutate, so there is no node identity to carry the answer
 * across. A tag-keyed count was tried and mis-attributed rows between two
 * captions in one document. It is a follow-up with its own design rather than
 * a predicate bolted onto this one - the same reason carve-php#1347 was
 * withdrawn.
 */
class ACaptionSlotFlattensBlocksTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    private function importWithRows(string $html): array
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $rows = [];
        foreach ($result->diagnostics as $diagnostic) {
            $rows[] = [$diagnostic->code, $diagnostic->message];
        }

        return [trim($result->value), $rows];
    }

    /**
     * THE REGRESSION THIS IS REALLY ABOUT: no invented characters.
     *
     * Rendering rather than reading the Carve, because the defect was invisible
     * in the source: `^ - a` contains the author's letters and looks preserved.
     * Only the rendered caption shows `- a` as literal text.
     */
    public function testNoListMarkerReachesTheRenderedCaption(): void
    {
        [$carve] = $this->importWithRows(
            '<table><caption><ul><li>a</li><li>b</li></ul></caption><tr><td>x</td></tr></table>',
        );
        $rendered = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<caption>a b</caption>', $rendered);
        $this->assertStringNotContainsString('- a', $rendered);
    }

    /**
     * The shapes the ticket names, and the figure slot beside the table one.
     *
     * `processFigure()` and `processTable()` write the caption line through
     * different methods, so a fix applied to one of them is not applied to the
     * other - which is exactly how this survived. Both are asserted.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function captionProvider(): array
    {
        return [
            'a list in a table caption' => [
                '<table><caption><ul><li>a</li><li>b</li></ul></caption><tr><td>x</td></tr></table>',
                "| x |\n^ a b",
            ],
            'a quote in a table caption' => [
                '<table><caption><blockquote><p>q</p></blockquote></caption><tr><td>x</td></tr></table>',
                "| x |\n^ q",
            ],
            'two paragraphs in a table caption' => [
                '<table><caption><p>one</p><p>two</p></caption><tr><td>x</td></tr></table>',
                "| x |\n^ one two",
            ],
            'a list in a figure caption' => [
                '<figure><img src="i.png"><figcaption><ul><li>a</li><li>b</li></ul></figcaption></figure>',
                "![](i.png)\n^ a b",
            ],
            'a quote in a figure caption' => [
                '<figure><img src="i.png"><figcaption><blockquote><p>q</p></blockquote></figcaption></figure>',
                "![](i.png)\n^ q",
            ],
        ];
    }

    #[DataProvider('captionProvider')]
    public function testEveryCaptionSlotFlattensToOneInlineRun(string $html, string $expected): void
    {
        [$carve] = $this->importWithRows($html);

        $this->assertSame($expected, $carve);
    }

    /**
     * The control: an ordinary inline caption is untouched and silent.
     *
     * Without this, the fix could have been "unwrap everything in a caption",
     * which would report a row for a caption that lost nothing.
     */
    public function testAnInlineCaptionIsUnchangedAndReportsNothing(): void
    {
        [$carve, $rows] = $this->importWithRows(
            '<table><caption>plain <em>and</em> marked</caption><tr><td>x</td></tr></table>',
        );

        $this->assertSame("| x |\n^ plain /and/ marked", $carve);
        $this->assertSame([], $rows);
    }

    /**
     * A BLOCK UNDER AN INLINE WRAPPER is flattened too, and the wrapper stays.
     *
     * `<a>` is transparent, so `<figcaption><a><ul>…</ul></a></figcaption>` is
     * valid HTML. A rule about the caption's DIRECT children missed it: the
     * link converted normally, resumed ordinary serialization underneath, and
     * put `- a` back inside the caption - the original defect surviving under a
     * wrapper, while the report claimed the list had been unwrapped.
     *
     * Both halves are asserted. The marker is gone AND the link survives, so
     * the fix cannot be "flatten the caption to text".
     */
    public function testABlockUnderAnInlineWrapperIsFlattenedAndTheWrapperKept(): void
    {
        [$carve] = $this->importWithRows(
            '<figure><img src="i.png"><figcaption><a href="/u"><ul><li>a</li></ul></a></figcaption></figure>',
        );
        $rendered = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<a href="/u">a</a>', $rendered);
        $this->assertStringNotContainsString('- a', $rendered);
    }

    /**
     * THE LIST-TABLE ROUTE writes a caption line too, and flattens it.
     *
     * `processTable()` hands a table with block-content cells to
     * `processTableAsListTable()`, which writes the caption into the fence's
     * QUOTED TITLE. An unflattened list put a raw newline and two markers
     * inside that quoted string, which is not valid Carve at all - it came back
     * as a literal paragraph beginning `::: list-table`, losing the whole
     * table. A caption slot is a caption slot on both routes.
     */
    public function testTheListTableRouteFlattensItsCaptionToo(): void
    {
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport(
            '<table><caption><ul><li>a</li><li>b</li></ul></caption>'
                . '<tr><td><blockquote><p>q</p></blockquote></td></tr></table>',
        );
        $rendered = (new CarveConverter())->convert($result->value);

        $this->assertStringContainsString('::: list-table "a b"', $result->value);
        $this->assertStringContainsString('<blockquote><p>q</p></blockquote>', $rendered);
    }

    /**
     * THE FIGURE FALLBACK IS NOT A CAPTION SLOT, so nothing is flattened.
     *
     * `processFigure()` has two paths. A figure whose body it cannot represent
     * writes the caption's content as ORDINARY BLOCKS rather than through a `^`
     * line - and ordinary blocks can hold a list, so flattening there would
     * destroy structure the output is perfectly able to carry, and report a
     * loss that did not happen.
     *
     * This is the row that fails if the flattening is ever decided from the
     * input DOM again: a `<figcaption>` inside a `<figure>` looks exactly like
     * the slot case and is not one.
     */
    public function testTheFigureFallbackKeepsItsListAndReportsNothing(): void
    {
        [$carve, $rows] = $this->importWithRows(
            '<figure><p>one</p><p>two</p><figcaption><ul><li>a</li><li>b</li></ul></figcaption></figure>',
        );
        $rendered = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString("- a\n- b", $carve);
        $this->assertStringContainsString('<li>a</li>', $rendered);
        $this->assertSame([], $rows);
    }

    /**
     * A NESTED CAPTION keeps its text when the figure around it dissolves.
     *
     * `<figcaption>` normally converts to nothing, because `processFigure()` is
     * expected to consume it. Once the figure has been unwrapped by the caption
     * slot, that early return dropped the author's words silently - the exact
     * failure mode this ticket is about, reintroduced one level down.
     */
    public function testANestedCaptionsTextSurvivesTheFlattening(): void
    {
        [$carve] = $this->importWithRows(
            '<table><caption><figure><img src="i.png"><figcaption>inner</figcaption></figure></caption>'
                . '<tr><td>x</td></tr></table>',
        );

        $this->assertStringContainsString('inner', $carve);
    }

    /**
     * STORED ROUND-TRIP SOURCE cannot smuggle block syntax into a caption.
     *
     * `data-djot-src` is emitted verbatim, so a caption's `<ul>` carrying one
     * put `- a` straight back into the inline slot, recreating the literal
     * marker this fix removes - and the serializer never ran, so a report built
     * from the input claimed a flattening that did not happen. Inside a caption
     * the stored source is not taken.
     */
    public function testStoredRoundTripSourceIsNotTakenInsideACaption(): void
    {
        $result = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport(
            '<table><caption><ul data-djot-src="- a"><li>a</li></ul></caption><tr><td>x</td></tr></table>',
        );
        $rendered = (new CarveConverter())->convert($result->value);

        $this->assertStringNotContainsString('- a', $rendered);
        $this->assertStringContainsString('<caption>a</caption>', $rendered);
    }

    /**
     * A block OUTSIDE a caption is still a block.
     *
     * The unwrapping is scoped to the slot that cannot hold structure. A list
     * in an ordinary table cell is a different question (carve-php#1164) and
     * must not start flattening because a caption does.
     */
    public function testAListOutsideACaptionIsStillAList(): void
    {
        [$carve] = $this->importWithRows('<ul><li>a</li><li>b</li></ul>');

        $this->assertSame("- a\n- b", $carve);
    }
}
