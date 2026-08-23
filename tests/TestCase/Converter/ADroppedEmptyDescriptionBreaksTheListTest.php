<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1636. carve#1627 ruled that an entry writing nothing is
 * dropped and its term written alone, which is right while the dropped entry is
 * the LAST one. Put an entry after it and the same import breaks the ceiling in
 * the other direction.
 *
 * Consecutive `::` lines SHARE the description written below them - that is the
 * `<dl>` model the syntax mirrors - so dropping the empty description and
 * writing both terms into one list gives `t1` the description `d2`, which it
 * never had.
 *
 * AN ADDITION IS NOT A LOSS AND NO ROW CAN DECLARE IT. A loss that stays inside
 * a declared ceiling is acceptable because the reader is told what is missing;
 * an addition changes what the surviving term MEANS, and a reader told the empty
 * description was dropped has been told nothing about `t1` acquiring `d2`. The
 * ceiling therefore binds in both directions.
 */
class ADroppedEmptyDescriptionBreaksTheListTest extends TestCase
{
    /**
     * @var string
     */
    private const NOT_LAST = '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>';

    /**
     * @var string
     */
    private const LAST = '<dl><dt>t1</dt><dd>d1</dd><dt>t2</dt><dd></dd></dl>';

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

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
     * @return list<array{string, string}>
     */
    private function rows(string $html): array
    {
        return array_map(
            static fn (array $row): array => [$row['code'], $row['path'] ?? ''],
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
        );
    }

    /**
     * The tree the shared fixture records: ONE list, four items, empty `<dd>`
     * kept. This is the AST-ingest path, which reaches the same writer.
     */
    private function ingested(): string
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'definition_list',
                    'items' => [
                        ['type' => 'definition_term', 'children' => [['type' => 'text', 'value' => 't1']]],
                        ['type' => 'definition_description', 'children' => []],
                        ['type' => 'definition_term', 'children' => [['type' => 'text', 'value' => 't2']]],
                        [
                            'type' => 'definition_description',
                            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'd2']]]],
                        ],
                    ],
                ],
            ],
        ]);

        return (new CarveRenderer())->render($document);
    }

    public function testItWritesTwoListsSeparatedByACommentLine(): void
    {
        $this->assertSame(":: t1\n\n%%\n\n:: t2\n:  d2\n", $this->import(self::NOT_LAST));
    }

    public function testTheSurvivingTermGainsNoDescriptionItNeverHad(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n</dl>\n<dl>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>\n",
            $this->converter->convert($this->import(self::NOT_LAST)),
        );
    }

    /**
     * A BLANK LINE IS NOT THE BREAK. It neither ends a definition list nor
     * loosens one, so the blank-line spelling is ONE list with two terms sharing
     * `d2` - the outcome the rule forbids - and the writer removes the blank line
     * again. Both halves are asserted, because either one alone would let the
     * blank-line spelling look adequate.
     */
    public function testABlankLineNeitherEndsTheListNorSurvivesTheWriter(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>\n",
            $this->converter->convert(":: t1\n\n:: t2\n:  d2\n"),
        );
        $this->assertSame(":: t1\n:: t2\n:  d2\n", $this->converter->toCarve(":: t1\n\n:: t2\n:  d2\n"));
    }

    public function testTheWrittenSourceIsAFixedPointOfTheWriter(): void
    {
        $written = $this->import(self::NOT_LAST);
        $this->assertSame($written, $this->converter->toCarve($written));
    }

    /**
     * TWO ROWS, IN DOCUMENT ORDER OF THE LOSING ELEMENT. `structure-split` is not
     * folded into `structure-unspellable`: that code is for a shape the syntax
     * cannot spell at all, and here every part is spellable, present and exact -
     * what the source cannot say is that they were one list.
     */
    public function testItDeclaresTheSplitAndTheDroppedDescriptionInThatOrder(): void
    {
        $this->assertSame(
            [['structure-split', '/dl[1]'], ['structure-unspellable', '/dl[1]/dd[2]']],
            $this->rows(self::NOT_LAST),
        );
    }

    /**
     * THE CONTROL carve#1627 already ruled. A dropped LAST entry has nothing
     * after it to lend a description to, so the term is written alone, the list
     * is not split, and no `structure-split` row is owed.
     */
    public function testItDoesNotSplitWhenTheDroppedEntryIsLast(): void
    {
        $this->assertSame(":: t1\n:  d1\n:: t2\n", $this->import(self::LAST));
        $this->assertSame(['structure-unspellable'], $this->codes(self::LAST));
    }

    public function testItDoesNotSplitAListWithNothingDropped(): void
    {
        $html = '<dl><dt>t1</dt><dd>d1</dd><dt>t2</dt><dd>d2</dd></dl>';
        $this->assertSame(":: t1\n:  d1\n:: t2\n:  d2\n", $this->import($html));
        $this->assertSame([], $this->codes($html));
    }

    /**
     * A SECOND DESCRIPTION OF THE SAME ENTRY IS NOT A NEW ENTRY, and breaking
     * there would cause the loss the rule exists to prevent rather than avoid it:
     * `: d2` written outside the list re-reads as a PARAGRAPH, so the description
     * is gone and a paragraph the input never had is in its place. The term
     * already has `d2`, so nothing is gained and there is nothing to declare.
     */
    public function testItDoesNotBreakBeforeAnotherDescriptionOfTheSameTerm(): void
    {
        $html = '<dl><dt>t</dt><dd></dd><dd>d2</dd></dl>';
        $this->assertSame(":: t\n:  d2\n", $this->import($html));
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d2</dd>\n</dl>\n",
            $this->converter->convert($this->import($html)),
        );
        $this->assertSame(['structure-unspellable'], $this->codes($html));
    }

    public function testItClearsTheMarkOnceTheSameEntryWritesADescriptionAfterAll(): void
    {
        $html = '<dl><dt>t1</dt><dd></dd><dd>d1</dd><dt>t2</dt><dd>d2</dd></dl>';
        $this->assertSame(":: t1\n:  d1\n:: t2\n:  d2\n", $this->import($html));
        $this->assertSame(['structure-unspellable'], $this->codes($html));
    }

    /**
     * EVERY dropped entry breaks, not just the first. Spending one separator for
     * a run of them would leave `:: t2` / `:: t3` / `: d3` in the second list,
     * and `t2` would acquire `d3` - the same addition one list further along. One
     * `structure-split` row still covers it: the row is about the `<dl>`, and the
     * grouping it lost is one fact however many pieces the list came out in.
     */
    public function testItBreaksAtEveryDroppedEntryNotOnlyTheFirst(): void
    {
        $html = '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd></dd><dt>t3</dt><dd>d3</dd></dl>';
        $this->assertSame(":: t1\n\n%%\n\n:: t2\n\n%%\n\n:: t3\n:  d3\n", $this->import($html));
        $this->assertSame(
            ['structure-split', 'structure-unspellable', 'structure-unspellable'],
            $this->codes($html),
        );
    }

    /**
     * THREE PATHS REACH THE WRITER and only the written result is common to them,
     * which is why the rule is written over "this entry writes nothing" rather
     * than over "the description is empty": an ingested tree and a parsed one do
     * not agree on what an empty description looks like.
     */
    public function testTheIngestedTreeTakesTheSameBranch(): void
    {
        $this->assertSame(":: t1\n\n%%\n\n:: t2\n:  d2\n", $this->ingested());
    }

    /**
     * A DESCRIPTION THAT WRITES NOTHING IS NOT ONLY AN EMPTY ONE. An invisible
     * paragraph and an empty list write nothing too, and the writer drops all
     * three alike - a fix written over "the description is empty" passes the
     * shared fixture and misses these.
     */
    public function testADescriptionWhoseBlocksWriteNothingTakesTheSameBranch(): void
    {
        $this->assertSame(
            ":: t1\n\n%%\n\n:: t2\n:  d2\n",
            $this->import('<dl><dt>t1</dt><dd><p>  </p></dd><dt>t2</dt><dd>d2</dd></dl>'),
        );
        $this->assertSame(
            ":: t1\n\n%%\n\n:: t2\n:  d2\n",
            $this->import('<dl><dt>t1</dt><dd><ul></ul></dd><dt>t2</dt><dd>d2</dd></dl>'),
        );
    }
}
