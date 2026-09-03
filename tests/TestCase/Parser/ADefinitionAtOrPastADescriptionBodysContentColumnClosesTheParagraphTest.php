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
 * question when this class was written. markup-carve/carve#1911 has since
 * ruled it the way carve-js and carve-rs already answered, and
 * markup-carve/carve#1917 wrote it into the spec, so the executable spec at
 * `main` ends the body too. The pinned revision predates that, which is why
 * the two spec readings part company on the at-or-past rows. Each provider
 * below says which readings its rows match - a cross-engine claim without a
 * revision beside it is a claim nobody can re-check.
 *
 * Measured against the executable spec at markup-carve/carve `95fc3a04`, the
 * revision `tests/spec` is pinned to and which answers alike to `f59cc880`,
 * `caec9ff` and `86569bd` before it, and at `a37a2cd4`, the tip of `main`,
 * which parts company with it where each provider says; and against carve-js
 * `3ca6d8c`. The carve-rs reading
 * is the `0.1.4` release binary, which is SHORT of this rule family - it fails
 * corpus `441-*-2`, added by markup-carve/carve#1897 and answered by
 * markup-carve/carve-rs#1507 after the release was cut - so it is evidence for
 * the rows below and not for the between-columns band.
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
     * carve-js, carve-rs and the executable spec at `main`; rows 0 to 3 match
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
     * Matches carve-js, carve-rs and the executable spec at `main` at every
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
     * THE SECOND CONTROL, and the one that says why the scope was the
     * definition and not "any block opener". When carve-php#1870 landed, a
     * heading past the column kept the flush-left line INSIDE the body in every
     * reading measured, so moving it would have been a divergence rather than a
     * fix. markup-carve/carve#1917 has since ruled the other way and the
     * executable spec at `main` ends the body here; carve-php#1874 is that
     * work, and this control is what will fail when it lands. Byte-identical
     * before and after carve-php#1872.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function headingBandProvider(): array
    {
        $inside = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <h1 id=\"h\">h</h1>\n    <p>tail</p>\n  </dd>\n</dl>";

        return [
            'column 0' => [0, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<section id=\"h\">\n  <h1>h</h1>\n  <p>tail</p>\n</section>"],
            'below the column, 1' => [1, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p># h\ntail</p>"],
            'below the column, 2' => [2, "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p># h\ntail</p>"],
            'at the column' => [3, "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n<p>tail</p>"],
            'one past the column' => [4, $inside],
            'two past the column' => [5, $inside],
        ];
    }

    #[DataProvider('headingBandProvider')]
    public function testTheHeadingControlDoesNotMove(int $column, string $expected): void
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
     * carve-js and carve-rs do.
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
}
