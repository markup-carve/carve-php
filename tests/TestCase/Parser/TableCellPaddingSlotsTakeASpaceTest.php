<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every table-cell padding slot takes a space, and nothing else.
 *
 * `resources/grammar.ebnf` PART 7, MARKER SEPARATORS AND PADDING SLOTS, decides
 * the terminal by POSITION: a tab is syntax ONLY in a line's leading
 * indentation run, and from the first non-whitespace character of the line
 * onward it satisfies no slot in any production. Every table-cell padding slot
 * sits after the row's opening `|`, so every one of them is inline and every one
 * of them is spelled `space`:
 *
 * - `delimiter_cell = {space}, [':'], '-', {'-'}, [':'], {space}`
 * - `header_cell = '=', [alignment_marker], {space}, cell_content, {space}`
 * - `data_cell = [cell_attributes], [alignment_marker], {space}, cell_content, {space}`
 * - `rowspan_marker = {space}, '^', {space}`
 * - `colspan_marker = {space}, '<', {space}`
 *
 * A non-space run in one of those slots is NOT a rejection. It stops being
 * padding and becomes ordinary cell CONTENT, so it stays exactly where it was
 * written. For the three cell productions that is the whole effect. For the
 * delimiter cell and the two span markers the failure is structural on top of
 * that: the cell is no longer a delimiter cell or a span marker, so no header is
 * promoted, no alignment is assigned and no span happens.
 *
 * CARDINALITY IS NOT AT ISSUE HERE. These five slots are spelled `{space}`, a
 * run, and stay a run. markup-carve/carve#912 tightened four OTHER slots -
 * `link_title`, the reference-definition attributes slot and the code-fence and
 * frontmatter opener slots - to exactly one space; none of them is a table slot.
 * The two-space and no-space controls below are what keeps the two questions
 * from being answered by the same edit.
 *
 * Six separate sites decided this in this engine, and they used three different
 * character classes, which is why each is pinned on its own:
 *
 * - the cell's content strip (`BlockParser`) was `trim()`, whose default
 *   charlist `" \t\n\r\0\x0B"` admits a tab AND a vertical tab but NOT a form
 *   feed;
 * - the delimiter-row test was PCRE `\s`, i.e. `[ \t\n\r\f\v]`, which admits all
 *   three;
 * - the two span-marker tests were `trim()` again;
 * - a continuation row's cells are `data_cell`s too, padded in a SECOND place
 *   (`TableParser::mergeCellContents`), so narrowing only the standard-row path
 *   left the continuation path joining the run away with nothing able to see it.
 *   That function strips the base cell and the continuation cell with two
 *   separate calls, which is four slots rather than two;
 * - and a cell carrying a glued `{...}` attribute block strips its leading slot
 *   in a THIRD place, with `ltrim($rest, " \t")`, before the content strip runs
 *   at all - so an attributed cell and a bare one disagreed about the same slot.
 *
 * The three-way disagreement is the reason the vertical tab and the form feed
 * get their own rows rather than riding along with the tab: before this fix a
 * form feed already survived a data cell and still satisfied a delimiter cell,
 * so one fixture cannot stand for a divergence it never covered.
 *
 * ONE SITE HERE IS A CONTROL, said out loud so nobody counts it as covered:
 * `TableParser::parseTableAlignments` was narrowed with the rest, and reverting
 * it alone breaks NOTHING in this file or in the suite. It is dead by
 * construction - it only ever runs on a row `isSeparatorRow` has already
 * accepted, and such a row's cells hold nothing but spaces, colons and dashes,
 * so the two charlists cannot differ there. It is narrowed anyway so that a
 * future widening of `isSeparatorRow` cannot silently re-admit a tab through it.
 */
class TableCellPaddingSlotsTakeASpaceTest extends TestCase
{
    /**
     * Every whitespace run that is not made of U+0020 alone.
     *
     * MIXED RUNS IN BOTH DIRECTIONS. The slot is a RUN, so a check on the FIRST
     * character after the pipe is not a check on the rule: a fix spelled "the
     * first character must be a space, then strip whitespace" passes a
     * tab-first fixture and admits `<SP><TAB>`; spelled against the LAST
     * character it admits `<TAB><SP>`. Both spellings have been written for
     * real in this org, in three languages, on one day.
     *
     * @var array<string, string>
     */
    private const NON_SPACE_RUNS = [
        'a tab' => "\t",
        'a vertical tab' => "\v",
        'a form feed' => "\f",
        'a space then a tab' => " \t",
        'a tab then a space' => "\t ",
    ];

    /**
     * The eight content slots, one row per slot per run.
     *
     * ONE SLOT PER ROW. Each template leaves the slot it is not testing exactly
     * as it was, so narrowing one slot cannot make another slot's row pass.
     *
     * The expectation is derived rather than written out because the derivation
     * IS the rule: the run of spaces in the slot is still consumed, and only the
     * part of the run that is not a space survives into the cell. So
     * `<SP><TAB>c` leaves `<TAB>c` (the leading space was padding) while
     * `<TAB><SP>c` leaves the whole run (nothing before the tab was padding).
     * A fix that stripped the whole run, or none of it, fails these rows in
     * opposite directions.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function contentSlotProvider(): array
    {
        $rows = [];
        foreach (self::NON_SPACE_RUNS as $runName => $run) {
            $lead = ltrim($run, ' ');
            $trail = rtrim($run, ' ');

            $rows["data cell, leading slot, {$runName}"] = [
                "| a | b |\n|{$run}c | d |\n",
                "<td>{$lead}c</td>",
            ];
            $rows["data cell, trailing slot, {$runName}"] = [
                "| a | b |\n| c{$run}| d |\n",
                "<td>c{$trail}</td>",
            ];
            $rows["header cell, leading slot, {$runName}"] = [
                "|={$run}h |= i |\n| 1 | 2 |\n",
                "<th scope=\"col\">{$lead}h</th>",
            ];
            $rows["header cell, trailing slot, {$runName}"] = [
                "|= h{$run}|= i |\n| 1 | 2 |\n",
                "<th scope=\"col\">h{$trail}</th>",
            ];
            // A continuation row's cells are `data_cell`s and are padded in a
            // second place, `TableParser::mergeCellContents`. The join between
            // the base cell and the continuation cell is a parser-inserted
            // single space, which is why the expected text is `a ` and then the
            // run - the join is not padding and does not move.
            //
            // FOUR SLOTS, NOT TWO. That function strips the BASE cell and the
            // CONTINUATION cell with two separate calls, so a row that only
            // pads the continuation side leaves the base side's charlist
            // untested: reverting it alone kept the whole file green. Once a
            // continuation row exists, the base row's own padding is decided
            // here rather than by the standard-row strip, so all four get a row.
            $rows["continuation cell, leading slot, {$runName}"] = [
                "| a | b |\n+{$run}x | y |\n",
                "<td>a {$lead}x</td>",
            ];
            $rows["continuation cell, trailing slot, {$runName}"] = [
                "| a | b |\n+ x{$run}| y |\n",
                "<td>a x{$trail}</td>",
            ];
            $rows["continued base cell, leading slot, {$runName}"] = [
                "|{$run}a | b |\n+ x | y |\n",
                "<td>{$lead}a x</td>",
            ];
            $rows["continued base cell, trailing slot, {$runName}"] = [
                "| a{$run}| b |\n+ x | y |\n",
                "<td>a{$trail} x</td>",
            ];
            // `data_cell = [cell_attributes], [alignment_marker], {space},
            // cell_content, {space}` - the slot after a glued `{...}` block is
            // the SAME leading slot, not a fresh one, and it is stripped in its
            // own place. Narrowing only the final content strip left this one
            // eating the run before the strip could ever see it, so an
            // attributed cell disagreed with a bare one about the same slot.
            $rows["attributed data cell, leading slot, {$runName}"] = [
                "| a | b |\n|{.x}{$run}c | d |\n",
                "<td class=\"x\">{$lead}c</td>",
            ];
        }

        return $rows;
    }

    /**
     * The six structural slots, one row per slot per run.
     *
     * Each row states BOTH halves of the answer: the construct does not happen,
     * and the run is still there in the cell that replaced it. Asserting only
     * the absence of the construct would pass for an engine that dropped the
     * row entirely, which is a different (and also wrong) outcome.
     *
     * The delimiter rows expect an em dash rather than `---`: once the line is
     * not a delimiter row its cells are ordinary content, and smart typography
     * resolves a three-dash run there like anywhere else. That is the tell that
     * the row really did become content rather than being silently dropped.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function structuralSlotProvider(): array
    {
        $rows = [];
        foreach (self::NON_SPACE_RUNS as $runName => $run) {
            $lead = ltrim($run, ' ');
            $trail = rtrim($run, ' ');

            $rows["delimiter cell, leading slot, {$runName}"] = [
                "| a | b |\n|{$run}--- | --- |\n| 1 | 2 |\n",
                "<td>{$lead}\u{2014}</td>",
                '<thead>',
            ];
            $rows["delimiter cell, trailing slot, {$runName}"] = [
                "| a | b |\n| ---{$run}| --- |\n| 1 | 2 |\n",
                "<td>\u{2014}{$trail}</td>",
                '<thead>',
            ];
            $rows["rowspan marker, leading slot, {$runName}"] = [
                "| a | b |\n|{$run}^ | c |\n",
                "<td>{$lead}^</td>",
                'rowspan',
            ];
            $rows["rowspan marker, trailing slot, {$runName}"] = [
                "| a | b |\n| ^{$run}| c |\n",
                "<td>^{$trail}</td>",
                'rowspan',
            ];
            $rows["colspan marker, leading slot, {$runName}"] = [
                "| a | b |\n| c |{$run}< |\n",
                "<td>{$lead}&lt;</td>",
                'colspan',
            ];
            $rows["colspan marker, trailing slot, {$runName}"] = [
                "| a | b |\n| c | <{$run}|\n",
                "<td>&lt;{$trail}</td>",
                'colspan',
            ];
        }

        return $rows;
    }

    /**
     * The controls: a space, a run of spaces and no padding at all.
     *
     * These are what stops the fix from being written as "any whitespace in a
     * padding slot kills the construct". The slot is a RUN of spaces and stays
     * one - markup-carve/carve#912 settled cardinality the other way for four
     * slots that are not these - so every row here has to keep working, and the
     * two-space rows are the ones a cardinality tightening would break.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spacePaddingProvider(): array
    {
        return [
            'data cell, one space' => ["| a | b |\n| c | d |\n", '<td>c</td>'],
            'data cell, two spaces' => ["| a | b |\n|  c  | d |\n", '<td>c</td>'],
            'data cell, no padding' => ["| a | b |\n|c| d |\n", '<td>c</td>'],
            'header cell, one space' => ["|= h |= i |\n| 1 | 2 |\n", '<th scope="col">h</th>'],
            'header cell, two spaces' => ["|=  h  |= i |\n| 1 | 2 |\n", '<th scope="col">h</th>'],
            'header cell, no padding' => ["|=h|=i|\n| 1 | 2 |\n", '<th scope="col">h</th>'],
            'delimiter cell, one space' => ["| a | b |\n| --- | --- |\n| 1 | 2 |\n", '<thead>'],
            'delimiter cell, two spaces' => ["| a | b |\n|  ---  | --- |\n| 1 | 2 |\n", '<thead>'],
            'delimiter cell, no padding' => ["| a | b |\n|---|---|\n| 1 | 2 |\n", '<thead>'],
            'rowspan marker, one space' => ["| a | b |\n| ^ | c |\n", 'rowspan="2"'],
            'rowspan marker, two spaces' => ["| a | b |\n|  ^  | c |\n", 'rowspan="2"'],
            'rowspan marker, no padding' => ["| a | b |\n|^| c |\n", 'rowspan="2"'],
            'colspan marker, one space' => ["| a | b |\n| c | < |\n", 'colspan="2"'],
            'colspan marker, two spaces' => ["| a | b |\n| c |  <  |\n", 'colspan="2"'],
            'colspan marker, no padding' => ["| a | b |\n| c |<|\n", 'colspan="2"'],
            'continuation cell, one space' => ["| a | b |\n+ x | y |\n", '<td>a x</td>'],
            'continuation cell, two spaces' => ["| a | b |\n+  x  | y |\n", '<td>a x</td>'],
            'continuation cell, no padding' => ["| a | b |\n+x|y|\n", '<td>a x</td>'],
            'attributed data cell, one space' => ["| a | b |\n|{.x} c | d |\n", '<td class="x">c</td>'],
            'attributed data cell, two spaces' => ["| a | b |\n|{.x}  c  | d |\n", '<td class="x">c</td>'],
            'attributed data cell, no padding' => ["| a | b |\n|{.x}c| d |\n", '<td class="x">c</td>'],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('contentSlotProvider')]
    public function testANonSpaceRunInAContentSlotIsCellContent(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    #[DataProvider('structuralSlotProvider')]
    public function testANonSpaceRunInAStructuralSlotUndoesTheConstruct(
        string $source,
        string $expectedCell,
        string $absentMarkup,
    ): void {
        $out = $this->html($source);

        $this->assertStringNotContainsString($absentMarkup, $out);
        $this->assertStringContainsString($expectedCell, $out);
    }

    #[DataProvider('spacePaddingProvider')]
    public function testASpaceStillFillsEveryPaddingSlot(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * The run in a padding slot is content, so it is inside the cell's span.
     *
     * The HTML rows above cannot tell a cell that KEPT the run from a cell whose
     * text merely renders the same bytes at the wrong offset - both slice to the
     * same string, which is the failure shape catalogued in
     * markup-carve/carve#755. markup-carve/carve#913 ruled `pos` markup-
     * inclusive with a containment invariant, so the text run's span is asserted
     * against the source it claims to cover.
     */
    public function testTheRunIsInsideTheTextRunsOwnSpan(): void
    {
        $source = "| a | b |\n|\tc | d |\n";
        $parser = new BlockParser(trackPositions: true);
        $encoded = (new AstCodec())->encode($parser->parse($source));

        $table = $encoded['children'][0];
        $cell = $table['rows'][1]['cells'][0];
        $text = $cell['children'][0];

        $this->assertSame("\tc", $text['value']);
        $this->assertSame(
            "\tc",
            substr($source, $text['pos']['startOffset'], $text['pos']['endOffset'] - $text['pos']['startOffset']),
        );
        $this->assertGreaterThanOrEqual($cell['pos']['startOffset'], $text['pos']['startOffset']);
        $this->assertLessThanOrEqual($cell['pos']['endOffset'], $text['pos']['endOffset']);
    }

    /**
     * A cell rebuilt from a continuation row is positioned from source CHUNKS,
     * and the chunks are stripped by their own pair of calls.
     *
     * This is where a second charlist inside one path does its damage silently.
     * A merged cell's text is not a run of any single line, so its inline
     * children are placed by matching the rebuilt content against the chunks the
     * base row and the continuation row each contributed. If a chunk is stripped
     * with a WIDER charlist than the content was, the rebuilt string no longer
     * equals the joined chunks, the whole map is discarded, and every inline node
     * in that cell silently loses its position - nothing renders differently and
     * no HTML row above can see it.
     *
     * Both directions are pinned because the two chunk producers are separate
     * functions: reverting either one alone left all 8,909 tests green before
     * these two assertions existed.
     */
    public function testAnInlineInARebuiltCellKeepsItsPositionBesideANonSpaceRun(): void
    {
        // The run sits on the BASE row's cell, so the base-row chunk decides.
        $source = "| /a/\t| b |\n+ x | y |\n";
        $emphasis = $this->firstEmphasis($source);
        $this->assertNotNull($emphasis['pos'], 'base-row chunk');
        $this->assertSame('/a/', $this->slice($source, $emphasis['pos']));

        // The run sits on the CONTINUATION row's cell, so the continuation
        // chunk decides.
        $source = "| a | b |\n+ /x/\t| y |\n";
        $emphasis = $this->firstEmphasis($source);
        $this->assertNotNull($emphasis['pos'], 'continuation-row chunk');
        $this->assertSame('/x/', $this->slice($source, $emphasis['pos']));
    }

    /**
     * @return array<string, mixed>
     */
    private function firstEmphasis(string $source): array
    {
        $parser = new BlockParser(trackPositions: true);
        $encoded = (new AstCodec())->encode($parser->parse($source));

        foreach ($encoded['children'][0]['rows'][0]['cells'] as $cell) {
            foreach ($cell['children'] ?? [] as $child) {
                if ($child['type'] === 'emphasis') {
                    return $child;
                }
            }
        }

        $this->fail('no emphasis node was built for ' . json_encode($source));
    }

    /**
     * @param string $source
     * @param array<string, int> $pos
     */
    private function slice(string $source, array $pos): string
    {
        return substr($source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']);
    }

    public function testEverySlotAndEveryRunIsStillCovered(): void
    {
        // A row silently dropped from a provider would take its slot's or its
        // character's coverage with it and nothing else here would fail. The
        // five sites reached three different character classes, so a shrinking
        // run list is a real regression in what this file proves.
        $this->assertCount(5, self::NON_SPACE_RUNS);
        $this->assertCount(45, self::contentSlotProvider());
        $this->assertCount(30, self::structuralSlotProvider());
        $this->assertCount(21, self::spacePaddingProvider());

        $this->assertSame(
            ["\t", "\v", "\f", " \t", "\t "],
            array_values(self::NON_SPACE_RUNS),
        );
    }
}
