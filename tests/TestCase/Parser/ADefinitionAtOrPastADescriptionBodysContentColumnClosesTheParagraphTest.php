<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition AT OR PAST a description body's content column ends the
 * paragraph under it.
 *
 * The grammar's DEFINITION BODIES FOLLOW THE SAME CONTAINER REACH RULE
 * (markup-carve/carve#956) makes the description body the third indented-block
 * collector, so PART 0's `CARVE-P0-020` AT OR PAST MEANS THE DEEPEST COLUMN THE
 * LINE REACHES (markup-carve/carve#1896) governs it exactly as it governs a
 * list item: past the body's column what is left is the body's own
 * indentation, and §10 I5 has the definition INTERRUPT the paragraph and
 * register rather than fold into it (carve-php#1870).
 *
 * carve-php#1868 moved the item host and could not carry this. That fix works
 * through the shared trailing-block tracker, and this collector never reaches
 * it for the line in question: a past-the-column line is appended to the open
 * paragraph entry before the tracker sees it, and the appending branch asks
 * `lineOpensBlockForLooseness()` with `invisibleArms: false`, which is what
 * keeps a definition BELOW the column folding as §24 C3 requires.
 *
 * Where the flush-left line goes once the body has ended was a separate
 * question when this class was written. markup-carve/carve#1911 ruled it and
 * markup-carve/carve#1917 wrote it into §24's BELOW THE BODY'S COLUMN THE BODY
 * ENDS, so the executable spec at `main` ends the body for a definition and
 * for a visible opener alike; carve-php#1874 is the half of that this engine
 * was missing. The pinned revision predates the ruling, which is why the two
 * spec readings part company on the at-or-past rows. Each provider below says
 * which readings its rows match - a cross-engine claim without a revision
 * beside it is a claim nobody can re-check.
 *
 * Measured against the executable spec at markup-carve/carve `95fc3a04`, the
 * revision `tests/spec` is pinned to and which answers alike to `f59cc880`,
 * `caec9ff` and `86569bd` before it, and at `35148309`, the tip of `main`,
 * which parts company with it where each provider says; and against carve-js
 * `3ca6d8c`.
 *
 * NO carve-rs READING IS CLAIMED HERE. The `0.1.4` release binary is short of
 * this rule family - it fails corpus `441-*-2`, added by
 * markup-carve/carve#1897 and answered by markup-carve/carve-rs#1507 after the
 * release was cut - and that engine has landed further parser fixes in this
 * area since, so the published artifact is evidence for nothing current and
 * was not used.
 */
class ADefinitionAtOrPastADescriptionBodysContentColumnClosesTheParagraphTest extends TestCase
{
    /**
     * The answer every at-or-past row gives: the body ends and `tail` is a
     * document-level paragraph.
     *
     * @var string
     */
    protected const ENDED = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>tail</p>";

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * A two-space separator puts the body's content column at 3. Column 0
     * reaches no container; 1 and 2 are BELOW the column, where §10 I5 makes
     * the definition ordinary text and the flush-left line folds in behind it;
     * 3 is AT it and 4 and 5 are PAST it, and the whole at-or-past half ends
     * the body.
     *
     * Columns 4 and 5 are the two carve-php answered alone. Every row matches
     * carve-js and the executable spec at `main`; rows 0 to 3 match
     * the pinned revision too, and it is the only reading left that keeps
     * `tail` inside the body on rows 4 and 5 - markup-carve/carve#1917 is what
     * moved `main` off it.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function bareBodyBandProvider(): array
    {
        return [
            'column 0' => [0, false],
            'below the column, 1' => [1, true],
            'below the column, 2' => [2, true],
            'at the column' => [3, false],
            'one past the column' => [4, false],
            'two past the column' => [5, false],
        ];
    }

    #[DataProvider('bareBodyBandProvider')]
    public function testTheReferenceDefinitionBand(int $column, bool $folds): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $expected = $folds
            ? "<dl>\n  <dt>t</dt>\n  <dd>a\n[r]: /url\ntail</dd>\n</dl>"
            : self::ENDED;
        $this->assertSame($expected, trim($html));
    }

    /**
     * THE OTHER DEFINITION SPELLING answers the same band, because the two
     * share the one predicate the appending branch now asks.
     */
    #[DataProvider('bareBodyBandProvider')]
    public function testTheFootnoteDefinitionBand(int $column, bool $folds): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "[^f]: x\ntail");

        $expected = $folds
            ? "<dl>\n  <dt>t</dt>\n  <dd>a\n[^f]: x\ntail</dd>\n</dl>"
            : self::ENDED;
        $this->assertSame($expected, trim($html));
    }

    /**
     * THE SEPARATOR SETS THE COLUMN, so the band moves with it rather than
     * sitting at a constant. A one-space separator puts the body's content
     * column at 2, and the whole at-or-past half starts one column earlier.
     * Without this the band above is satisfied by any rule that happens to
     * agree at 3.
     *
     * Matches carve-js and the executable spec at `main` at every
     * column; rows 0 to 2 match the pinned revision too, and rows 3 and 4 are
     * where it still keeps `tail` inside.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function narrowBodyBandProvider(): array
    {
        return [
            'column 0' => [0, false],
            'below the column' => [1, true],
            'at the column' => [2, false],
            'one past the column' => [3, false],
            'two past the column' => [4, false],
        ];
    }

    #[DataProvider('narrowBodyBandProvider')]
    public function testTheSeparatorWidthMovesTheBand(int $column, bool $folds): void
    {
        $html = $this->converter->convert(":: t\n: a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $expected = $folds
            ? "<dl>\n  <dt>t</dt>\n  <dd>a\n[r]: /url\ntail</dd>\n</dl>"
            : self::ENDED;
        $this->assertSame($expected, trim($html));
    }

    /**
     * THE DEFINITION REGISTERS, which is the half `CARVE-P0-020` actually
     * settles and the half the rendered band cannot show: a definition renders
     * nothing either way, so only a later use tells the two readings apart.
     * Before this fix `[r]: /url` reached the output verbatim and `[r][]` had
     * nothing to resolve. Matches all four readings on the resolved link.
     */
    public function testADefinitionPastTheColumnBothEndsTheBodyAndRegisters(): void
    {
        $html = $this->converter->convert(":: t\n:  a\n    [r]: /url\ntail\n\nSee [r][].");

        $this->assertSame(
            self::ENDED . "\n<p>See <a href=\"/url\">r</a>.</p>",
            trim($html),
        );
    }

    /**
     * THE CONTROL THAT MUST NOT MOVE. §10's A COMMENT IS THE ONE EXCEPTION
     * already ended the body at every column here (carve-php#1866), so a
     * comment is what says this change is scoped to the definition rather than
     * to the appending branch as a whole. Byte-identical before and after, and
     * matches all four readings.
     *
     * @return array<string, array{0: int}>
     */
    public static function commentBandProvider(): array
    {
        return [
            'column 0' => [0],
            'column 1' => [1],
            'column 2' => [2],
            'column 3' => [3],
            'column 4' => [4],
            'column 5' => [5],
        ];
    }

    #[DataProvider('commentBandProvider')]
    public function testTheCommentControlDoesNotMove(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame(self::ENDED, trim($html));
    }

    /**
     * A VISIBLE OPENER ANSWERS THE SAME BAND. This was the control that said
     * carve-php#1870 was scoped to the definition: a heading past the column
     * kept the flush-left line INSIDE the body in every reading measured then,
     * so moving it would have been a divergence rather than a fix.
     * markup-carve/carve#1911 ruled the other way and markup-carve/carve#1917
     * wrote it in, so `# h` at 4 and 5 now answers as it does at 3 - the two
     * columns cannot part company, because §10 I1 closes the paragraph for the
     * visible opener exactly where §10 I5 closes it for a definition, and an
     * answer that moves between them is reading indentation rather than the
     * rule (carve-php#1874).
     *
     * Rows 3, 4 and 5 are corpus `444-*-4`, `444-*-3` and its two-past
     * spelling. Every row matches the executable spec at `35148309`, the tip of
     * `main`. The PINNED revision keeps `tail` inside at 4 and 5, and so does
     * carve-js `3ca6d8c`: its half of the ruling, markup-carve/carve-js#1604,
     * is still open. So is carve-rs's, markup-carve/carve-rs#1525, and that
     * engine was NOT run for this class - a ticket state is not a measurement,
     * and no published carve-rs artifact is current for this rule family.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function headingBandProvider(): array
    {
        $ended = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n<p>tail</p>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<section id=\"h\">\n  <h1>h</h1>\n  <p>tail</p>\n</section>"],
            'below the column, 1' => [1, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p># h\ntail</p>"],
            'below the column, 2' => [2, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p># h\ntail</p>"],
            'at the column' => [3, $ended],
            'one past the column' => [4, $ended],
            'two past the column' => [5, $ended],
        ];
    }

    #[DataProvider('headingBandProvider')]
    public function testTheHeadingBandAnswersLikeTheDefinitionBand(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "# h\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * A CONTAINER OPEN INSIDE THE BODY was out of reach of carve-php#1870's
     * change, which is why these two rows were added as a KNOWN DIVERGENCE:
     * the branch that fix touches can only ever continue the body's own
     * marker-line paragraph, so a body that opens a list on its own marker line
     * hands the question to the nested item's collector instead. carve-php#1872
     * is where that second path caught up, and both rows now answer the way
     * carve-js does.
     *
     * The body's column is 3 and the nested item's is 5. `CARVE-P0-020` reads
     * column 4 against the BODY - past it, and reaching nothing deeper - and
     * column 5 against the ITEM; either way the line is a definition, §10 I5
     * has it interrupt the paragraph, and the body is left with none, so `tail`
     * lands outside. Both spec revisions still fold the whole run at column 4
     * and keep `tail` inside at column 5 here, where markup-carve/carve#1917
     * moved the bare body: the executable spec registers a between-columns
     * definition in a list item and not in a description body, filed as
     * markup-carve/carve#1918.
     *
     * @return array<string, array{0: int}>
     */
    public static function nestedListInTheBodyProvider(): array
    {
        return [
            'past the body column, below the item' => [4],
            'at the item column' => [5],
        ];
    }

    #[DataProvider('nestedListInTheBodyProvider')]
    public function testANestedListInTheBodyEndsTheBodyToo(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * A THEMATIC BREAK IS THE SAME §10 I1 OPENER, and the one payload that
     * renders as a leaf: it cannot hold the follower even in principle, so the
     * follower's home is decided entirely by whether the body still carries an
     * open paragraph. Corpus `444-*-5` is column 4.
     *
     * Matches the executable spec at `35148309` at every column.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function thematicBreakBandProvider(): array
    {
        $ended = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <hr>\n  </dd>\n</dl>\n<p>tail</p>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<hr>\n<p>tail</p>"],
            'below the column, 1' => [1, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>***\ntail</p>"],
            'below the column, 2' => [2, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>***\ntail</p>"],
            'at the column' => [3, $ended],
            'one past the column' => [4, $ended],
            'two past the column' => [5, $ended],
        ];
    }

    #[DataProvider('thematicBreakBandProvider')]
    public function testTheThematicBreakBandAnswersLikeTheDefinitionBand(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "***\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * A TABLE ROW IS THE THIRD SPELLING, and it says the answer is about the
     * paragraph rather than about which element the opener produces: a table
     * COULD hold the follower as a continuation row, and does not.
     * Corpus `444-*-6` is column 4.
     *
     * Columns 3 to 5 match the executable spec at `35148309`. Columns 0 to 2
     * do NOT, and did not before this change either: below the body's column a
     * table row folds as text here and opens a table there. That is §24 C3's
     * own band and a separate defect from this one - it is pinned here so a
     * later fix to it has to say so.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function tableRowBandProvider(): array
    {
        $table = "<table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>";
        $ended = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    " . $table . "\n  </dd>\n</dl>\n<p>tail</p>";
        $folded = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>| a |\ntail</p>";

        return [
            'below the column, 1' => [1, $folded],
            'below the column, 2' => [2, $folded],
            'at the column' => [3, $ended],
            'one past the column' => [4, $ended],
            'two past the column' => [5, $ended],
        ];
    }

    #[DataProvider('tableRowBandProvider')]
    public function testTheTableRowBandAnswersLikeTheDefinitionBand(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "| a |\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * AN ATTRIBUTE BLOCK IS THE §10 I5 HALF THE DEFINITION SHARES, and the one
     * spelling that needed a second site: a visible opener already reached the
     * push branch, and a comment reached it through
     * `lineOpensBlockForLooseness()`'s own `%%` arm, but an attribute line was
     * still folded into the paragraph by the appending branch one column past
     * the body's own - while the SAME line at the column ended it. Corpus
     * `444-*-7` against `444-*-8` is that pair.
     *
     * Matches the executable spec at `35148309` at every column.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function attributeBlockBandProvider(): array
    {
        $folded = "<dl>\n  <dt>t</dt>\n  <dd>a\n{.k}\ntail</dd>\n</dl>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p class=\"k\">tail</p>"],
            'below the column, 1' => [1, $folded],
            'below the column, 2' => [2, $folded],
            'at the column' => [3, self::ENDED],
            'one past the column' => [4, self::ENDED],
            'two past the column' => [5, self::ENDED],
        ];
    }

    #[DataProvider('attributeBlockBandProvider')]
    public function testTheAttributeBlockBandAnswersLikeTheDefinitionBand(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "{.k}\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * AN OPENER THAT LEAVES A PARAGRAPH OPEN IS NOT COVERED, and this is the
     * row a fix that closes the body for every opener breaks. A block quote
     * opens inside the `dd`, its OWN paragraph is still open, and the
     * flush-left line lazily continues THE QUOTE - so the follower stays inside
     * at every at-or-past column. Corpus `444-*-11` is column 4.
     *
     * Matches the executable spec at `35148309` at every column, and is
     * byte-identical before and after this change.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function blockQuoteBandProvider(): array
    {
        $inside = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <blockquote><p>q\ntail</p></blockquote>\n  </dd>\n</dl>";
        $folded = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>&gt; q\ntail</p>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<blockquote><p>q\ntail</p></blockquote>"],
            'below the column, 1' => [1, $folded],
            'below the column, 2' => [2, $folded],
            'at the column' => [3, $inside],
            'one past the column' => [4, $inside],
            'two past the column' => [5, $inside],
        ];
    }

    #[DataProvider('blockQuoteBandProvider')]
    public function testABlockQuoteKeepsTheFollower(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "> q\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * OVER-INDENTED ORDINARY TEXT OPENS NOTHING, so the paragraph stays open
     * and BOTH lines fold into the body at every column. The second row this
     * change must not move, and the one that says the new read is asked only
     * of a line the authored-base pass would rebase. Corpus `444-*-10` is
     * column 4.
     *
     * Matches the executable spec at `35148309` at every column, and is
     * byte-identical before and after this change.
     *
     * @return array<string, array{0: int}>
     */
    public static function overIndentedProseProvider(): array
    {
        return [
            'column 0' => [0],
            'below the column, 1' => [1],
            'below the column, 2' => [2],
            'at the column' => [3],
            'one past the column' => [4],
            'two past the column' => [5],
        ];
    }

    #[DataProvider('overIndentedProseProvider')]
    public function testOverIndentedProseStillFolds(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "more\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>a\nmore\ntail</dd>\n</dl>",
            trim($html),
        );
    }

    /**
     * THE BAND REACHES THE LINE BELOW THE ENDED BODY, whatever its shape. Once
     * the heading has closed the body's paragraph the body has ENDED, so a
     * definition one column in is not §24's lazy text: at document level
     * column 1 is not column 0, the line opens nothing, and it is ordinary
     * paragraph text. Corpus `444-*-14` (heading one past) and `444-*-15` (at
     * the column) are the column-1 rows, and the two heading columns answer
     * alike here for the same reason every other pair in this class does.
     *
     * Matches the executable spec at `35148309` on all eight rows.
     *
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function belowTheEndedBodyProvider(): array
    {
        $only = "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>definition</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>";
        $text = $only . "\n<p>[r]: /url</p>";
        $rows = [];
        foreach ([3 => 'at the column', 4 => 'one past the column'] as $heading => $label) {
            $rows[$label . ', definition at 0'] = [$heading, 0, $only];
            $rows[$label . ', definition at 1'] = [$heading, 1, $text];
            $rows[$label . ', definition at 2'] = [$heading, 2, $text];
            $rows[$label . ', definition at 3'] = [$heading, 3, $only];
        }

        return $rows;
    }

    #[DataProvider('belowTheEndedBodyProvider')]
    public function testTheLineBelowTheEndedBodyOpensNothing(int $heading, int $column, string $expected): void
    {
        $html = $this->converter->convert(
            ":: term\n:  definition\n" . str_repeat(' ', $heading) . "# H\n"
                . str_repeat(' ', $column) . '[r]: /url',
        );

        $this->assertSame($expected, trim($html));
    }

    /**
     * A CONTAINER OPEN INSIDE THE BODY TAKES THE QUESTION BACK. Once the body
     * has opened a list of its own, every line above that list's content column
     * belongs to the ITEM and its collector reads them; the body has no opener
     * of its own to see there, so its tracker must not read one. `: - a` puts
     * the body's column at 2 and the item's at 4.
     *
     * MEASURED, not assumed. Spelled the way carve-php#1878 spells the same
     * guard at the push branch - "below the nested column, read it as the
     * body's" - column 3 closed the body, where all four readings fold the
     * whole run into the item. That shape was 64 documents right to wrong over
     * an 8370-document sweep of description bodies that open a container.
     *
     * Column 3 matches the executable spec at `35148309`; 4 to 6 match carve-js
     * `3ca6d8c`, and the spec parts company at 5 and 6 over where `tail` lands,
     * which is the between-columns band markup-carve/carve#1918 is about and
     * not this clause.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function containerInTheBodyProvider(): array
    {
        $inItem = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <h1 id=\"H\">H</h1>\n        tail\n      </li>\n    </ul>\n  </dd>\n</dl>";

        return [
            'between the two columns' => [3, "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n# H\ntail</li>\n    </ul>\n  </dd>\n</dl>"],
            'at the item column' => [4, "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ul>\n    <p>tail</p>\n  </dd>\n</dl>"],
            'one past the item column' => [5, $inItem],
            'two past the item column' => [6, $inItem],
        ];
    }

    #[DataProvider('containerInTheBodyProvider')]
    public function testAContainerOpenInsideTheBodyKeepsTheOpener(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n: - a\n" . str_repeat(' ', $column) . "# H\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * AN ABSORBING COLON FENCE IS NOT AN OPAQUE BLOCK. `:::note` fails §12's
     * opener test - a type word must be separated from the fence - so it is
     * paragraph text and opens nothing; the authored-base pass therefore DOES
     * rebase an opener written under it, and the tracker has to read it the
     * same way. Refusing the read on `absorbingFence`, which the state carries
     * alongside `inFence` and `inDiv` and which reads like a third opaque
     * state, left this answering against every other reading.
     *
     * Matches the executable spec at `35148309` at every column, and carve-js
     * `3ca6d8c` at 3.
     *
     * @return array<string, array{0: int}>
     */
    public static function absorbingFenceProvider(): array
    {
        return [
            'at the column' => [3],
            'one past the column' => [4],
            'two past the column' => [5],
        ];
    }

    #[DataProvider('absorbingFenceProvider')]
    public function testAnAbsorbingColonFenceIsNotAnOpaqueBlock(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  :::note\n" . str_repeat(' ', $column) . "# h\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>:::note</p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * INSIDE A CODE FENCE THE INDENTATION IS CONTENT, so the read above must
     * not reach it: `# h` under an open fence is verbatim payload and its
     * leading spaces are part of the block. Each column keeps exactly the
     * spaces the author wrote past the body's own, which is what says the read
     * is refused rather than merely answering the same.
     *
     * Matches carve-js `3ca6d8c` at every column, and is byte-identical before
     * and after this change.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function opaqueBodyProvider(): array
    {
        return [
            'at the column' => [3, '# h'],
            'one past the column' => [4, ' # h'],
            'two past the column' => [5, '  # h'],
        ];
    }

    #[DataProvider('opaqueBodyProvider')]
    public function testAnOpenFenceKeepsItsPayloadsIndentation(int $column, string $payload): void
    {
        $html = $this->converter->convert(":: t\n:  ```\n" . str_repeat(' ', $column) . "# h\n   ```\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>" . $payload . "\n</code></pre>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * AND THE ATTRIBUTE GUARD DOES NOT REACH FENCE PAYLOAD EITHER. It is asked
     * in the collector, before the entry read above, so nothing in the read's
     * own refusals protects it - what does is `$formABlockOpen`, armed by the
     * fence line itself. An attribute-shaped payload line keeps exactly the
     * spaces the author wrote past the body's column, which is what says so
     * (raised by codex review).
     *
     * Matches carve-js `3ca6d8c` at every column, and is byte-identical before
     * and after this change.
     */
    #[DataProvider('opaqueBodyProvider')]
    public function testAnOpenFenceKeepsAnAttributeShapedPayload(int $column, string $payload): void
    {
        $html = $this->converter->convert(":: t\n:  ```\n" . str_repeat(' ', $column) . "{.k}\n   ```\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>" . str_replace('# h', '{.k}', $payload)
                . "\n</code></pre>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * THE WRAPPED ATTRIBUTE SPELLING DOES NOT ANSWER LIKE THE SINGLE-LINE ONE,
     * and that asymmetry is the ruled answer rather than an oversight. A code
     * review read the guard above as incomplete - it asks
     * `isBlockAttributeLine()`, which sees `{.k}` and not a `{.a` / `.b}` pair -
     * and proposed extending it to the wrapped form. Measured instead: at
     * columns 4 and 5 the executable spec at `35148309` and carve-js `3ca6d8c`
     * BOTH fold the wrapped run as text and keep `tail` inside, which is what
     * this engine already does, so extending the guard would have moved two
     * columns away from every other reading.
     *
     * Columns 0 to 2 diverge from the spec, before and after this change and in
     * the same direction: the wrapped form is its own pre-existing band and is
     * pinned here as one, not as agreement.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function wrappedAttributeProvider(): array
    {
        $folded = "<dl>\n  <dt>t</dt>\n  <dd>body\n{.a\n.b}\ntail</dd>\n</dl>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p class=\"a b\">tail</p>"],
            'below the column, 1' => [1, $folded],
            'below the column, 2' => [2, $folded],
            'at the column' => [3, "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>tail</p>"],
            'one past the column' => [4, $folded],
            'two past the column' => [5, $folded],
        ];
    }

    #[DataProvider('wrappedAttributeProvider')]
    public function testTheWrappedAttributeSpellingDoesNotMove(int $column, string $expected): void
    {
        $pad = str_repeat(' ', $column);
        $html = $this->converter->convert(":: t\n:  body\n" . $pad . "{.a\n" . $pad . ".b}\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * A FOOTNOTE BODY IS A CONTAINER THE COLUMN CANNOT SEE. The tracker carries
     * an open footnote body in `inFootnoteBody` and leaves `nestedColumn` at 0,
     * so a refusal written on the column alone answered "nothing is open
     * inside the body" for it and read the footnote body's own content as the
     * description's opener (raised by codex review). Byte-identical before and
     * after this change at every column with the refusal asking both.
     *
     * These rows pin THIS ENGINE'S answer, not an agreement: the executable
     * spec at `35148309` and carve-js `3ca6d8c` both read the whole run as the
     * footnote's body and `tail` as the description, which carve-php does at no
     * column. That is a separate defect about a footnote definition written as
     * a description body, and it is unchanged here.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function footnoteBodyInTheBodyProvider(): array
    {
        return [
            'at the body column' => [3, true],
            'one past the body column' => [4, true],
            'at the footnote body column' => [5, false],
            'one past it' => [6, false],
            'two past it' => [7, false],
        ];
    }

    #[DataProvider('footnoteBodyInTheBodyProvider')]
    public function testAFootnoteBodyInsideTheBodyKeepsItsContent(int $column, bool $inside): void
    {
        $html = $this->converter->convert(":: t\n:  [^f]: note\n" . str_repeat(' ', $column) . "- nested\ntail");

        $expected = $inside
            ? "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>nested\ntail</li>\n    </ul>\n  </dd>\n</dl>"
            : "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>nested</li>\n    </ul>\n  </dd>\n</dl>\n<p>tail</p>";
        $this->assertSame($expected, trim($html));
    }
}
