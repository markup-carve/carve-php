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
 * Where the flush-left line goes once the body has ended is a separate question
 * the four readings do not settle together: carve-js and carve-rs put it
 * outside, the executable spec keeps it inside, and that is filed as
 * markup-carve/carve#1911. Each provider below says which readings its rows
 * match - a cross-engine claim without a revision beside it is a claim nobody
 * can re-check.
 *
 * Measured against the executable spec at markup-carve/carve `f59cc880`, which
 * answers every row below identically to `86569bd`, the revision `tests/spec`
 * is pinned to; against carve-js `6be9f3c`; and against the carve-rs release
 * binary that reproduces corpus `441-*` byte for byte.
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
     * carve-js and carve-rs; rows 0 to 3 match all four readings, and the
     * executable spec keeps `tail` inside the body on rows 4 and 5
     * (markup-carve/carve#1911).
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
     * Matches carve-js and carve-rs at every column; rows 0 to 2 match all four
     * readings (markup-carve/carve#1911 covers rows 3 and 4).
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
     * THE SECOND CONTROL, and the one that says why the scope is the definition
     * and not "any block opener". A heading past the column keeps the
     * flush-left line INSIDE the body in every reading measured - the oracle,
     * carve-js and this engine alike - so moving it would be a divergence
     * rather than a fix. It is the heading half of markup-carve/carve#1911.
     * Byte-identical before and after.
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
     * A CONTAINER OPEN INSIDE THE BODY IS OUT OF REACH of this change, because
     * the appending branch it fixes can only ever continue the body's own
     * marker-line paragraph: any line at or past the content column is pushed
     * as its own body entry first, and that is what arms the guard the branch
     * reads. A body that opens a list on its own marker line therefore hands
     * the question to the nested item's collector, and both rows below are
     * byte-identical before and after.
     *
     * They PIN A KNOWN DIVERGENCE rather than an agreement. The body's column
     * is 3 and the nested item's is 5; carve-js and carve-rs end the item at
     * column 4 and put `tail` outside the body at both columns, and this engine
     * does neither. `CARVE-P0-020` reads column 4 against the body and column 5
     * against the item, so the engines look right - filed as carve-php#1872,
     * and pinned here so it fails loudly when it lands.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function nestedListInTheBodyProvider(): array
    {
        return [
            'past the body column, below the item' => [
                4,
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n[r]: /url</li>\n    </ul>\n  </dd>\n</dl>\n<p>tail</p>",
            ],
            'at the item column' => [
                5,
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>tail</p>\n  </dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('nestedListInTheBodyProvider')]
    public function testANestedListInTheBodyIsUnchanged(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame($expected, trim($html));
    }
}
