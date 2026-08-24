<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `structure-unspellable` row for a dropped `<dd>` is decided by what the
 * description WRITES, not by what it HOLDS (markup-carve/carve-php#1649).
 *
 * The row was asked of `hasImportContentToUnwrap()`, which returns true as soon
 * as a `<dd>` has a child element outside the active set. The writer that
 * actually drops the description asks a different question - whether rendering
 * its children produces anything - and the two disagree on exactly two shapes:
 * `<dd><p> </p></dd>` and `<dd><ul></ul></dd>` each HOLD an element and each
 * WRITE nothing. Both were dropped with no row.
 *
 * `docs/html-import.md`, "a declared loss is a ceiling, not a licence": the row
 * is the thing that PERMITS the drop, so an undeclared drop is the half the
 * ceiling does not cover - which is the reasoning that added this row at all
 * (carve-php#1615).
 *
 * THE SAME MISTAKE, ONCE IN EACH DIRECTION. The neighbouring `structure-split`
 * row had it too and was fixed by having the writer record its answer for the
 * report to read back (carve-php#1646); the `<dd>` row sat next to that record
 * and did not use one. It now keeps its own, so the row and the source cannot
 * answer differently.
 *
 * NOTHING THE IMPORTER WRITES MOVES. All three shapes were already dropped and
 * already split; what changes is that the drop is now declared. The written
 * source is pinned below for exactly that reason.
 */
class ADroppedDescriptionIsDeclaredByWhatItWritesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function codes(string $html): array
    {
        return array_map(
            static fn (array $row): string => $row['code'],
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
        );
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function droppedDescriptionProvider(): array
    {
        return [
            // Holds nothing AND writes nothing. This one always had its row -
            // the two predicates agree here, which is why the defect hid.
            'an empty description' => [
                '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>',
                ['structure-split', 'structure-unspellable'],
            ],
            // HOLDS a paragraph, WRITES nothing: PART 11 §7 keeps no block whose
            // every character is layout, so the `<p>` goes and the description
            // is empty. `element-dropped` was already declared for the
            // paragraph; the description's own drop was not.
            'a description holding a whitespace-only paragraph' => [
                '<dl><dt>t1</dt><dd><p>  </p></dd><dt>t2</dt><dd>d2</dd></dl>',
                ['structure-split', 'structure-unspellable', 'element-dropped'],
            ],
            // HOLDS a list, WRITES nothing: a `<ul>` with no `<li>` builds no
            // list. This shape declared nothing about the description at all.
            'a description holding an empty list' => [
                '<dl><dt>t1</dt><dd><ul></ul></dd><dt>t2</dt><dd>d2</dd></dl>',
                ['structure-split', 'structure-unspellable'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param list<string> $expected
     */
    #[DataProvider('droppedDescriptionProvider')]
    public function testADroppedDescriptionIsDeclaredWhateverItHolds(string $html, array $expected): void
    {
        // Verified against carve-js `main` while writing this: it reports the
        // same three sets, which is what makes these the right expectations
        // rather than merely this engine's current output.
        $this->assertSame($expected, $this->codes($html));
    }

    /**
     * THE BOUND, and the near miss for the fix. A description that writes
     * SOMETHING keeps its line and takes no row, however little it writes. A
     * `<dd>` whose only child renders to a non-breaking space writes a colon and
     * three spaces and round-trips exactly, so a fix that declared every `<dd>`
     * with no element content would wrongly claim this one was dropped.
     *
     * @return array<string, array{string}>
     */
    public static function survivingDescriptionProvider(): array
    {
        return [
            'an ordinary description' => ['<dl><dt>t1</dt><dd>d1</dd></dl>'],
            'a description holding a no-break space' => ['<dl><dt>t1</dt><dd>&#160;</dd></dl>'],
            'a description holding a paragraph with text' => ['<dl><dt>t1</dt><dd><p>d1</p></dd></dl>'],
        ];
    }

    #[DataProvider('survivingDescriptionProvider')]
    public function testADescriptionThatWritesSomethingTakesNoRow(string $html): void
    {
        $this->assertNotContains('structure-unspellable', $this->codes($html));
    }

    /**
     * The source is UNCHANGED by this. All three shapes already dropped the
     * description and already broke the list; only the declaration was missing,
     * so a change that moved the written source would be a different and larger
     * claim than this one makes.
     *
     * @param string $html
     * @param list<string> $expected the provider's codes, not used here
     */
    #[DataProvider('droppedDescriptionProvider')]
    public function testTheWrittenSourceIsUnchanged(string $html, array $expected): void
    {
        $this->assertSame(":: t1\n\n%%\n\n:: t2\n:  d2\n", (new HtmlToCarve())->convert($html));
    }
}
