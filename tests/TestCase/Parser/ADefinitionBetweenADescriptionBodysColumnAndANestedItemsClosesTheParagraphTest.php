<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition written inside a description body that has a LIST OPEN IN IT
 * ends the paragraph, whether it reaches the nested item or only the body.
 *
 * PART 0's `CARVE-P0-020` AT OR PAST MEANS THE DEEPEST COLUMN THE LINE REACHES
 * (markup-carve/carve#1896) answers the definition against the innermost open
 * container the line REACHES. A two-space separator with a bullet after it
 * opens two of them - the body at content column 3 and the nested item at 5 -
 * so a definition at column 4 registers against the BODY and one at column 5
 * against the ITEM. Either way §10 I5 has it interrupt the paragraph, and the
 * body is left with none, so a flush-left line below it is a document-level
 * paragraph (carve-php#1872).
 *
 * carve-php#1870 moved the body's own marker-line paragraph and could not carry
 * this. Its branch only ever CONTINUES that paragraph: a line at or past the
 * content column is pushed as its own body entry first, which is what arms the
 * guard the branch reads. A body that opens a list therefore hands the line to
 * the nested item's collector, and the entry it hands over still carried the
 * indentation left after the body's own column - so the item read a definition
 * bound for the body as its own prose.
 *
 * WHICH CONTAINER THE LINE REACHES IS LOAD-BEARING here, unlike in
 * carve-php#1870. Below the item's column the entry has to arrive at the body's
 * column or the item collects it as text; at or past that column it is the
 * item's own line and its indentation is what keeps the item's later content
 * inside it. `testTheItemKeepsCollectingAtItsOwnColumn` is that control.
 *
 * Measured against the executable spec at markup-carve/carve `a37a2cd4`, the
 * tip of `main`, and at `95fc3a04`, the revision `tests/spec` is pinned to -
 * the two part company elsewhere in the host but answer every row below alike;
 * against carve-js `3ca6d8c`; and against the carve-rs `0.1.4` release binary,
 * which is SHORT of this rule family - it fails corpus `441-*-2`, added by
 * markup-carve/carve#1897 and answered by markup-carve/carve-rs#1507 after the
 * release was cut, so where a row says carve-rs the claim is about that binary
 * and not about the rule. Each provider says which readings its rows match; a
 * cross-engine claim with no revision beside it is one nobody can re-check.
 */
class ADefinitionBetweenADescriptionBodysColumnAndANestedItemsClosesTheParagraphTest extends TestCase
{
    /**
     * The answer every at-or-past row gives: the list is the body's only
     * content and `tail` is a document-level paragraph.
     *
     * @var string
     */
    protected const ENDED = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>tail</p>";

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * A two-space separator puts the body's content column at 3 and the nested
     * item's at 5. Column 0 reaches no container and 3 to 7 are the at-or-past
     * half; 1 and 2 are BELOW the body's column, where this engine still stands
     * alone - see `theBelowColumnBandProvider()`.
     *
     * Every row here matches carve-js and carve-rs. Both spec revisions part
     * company from 4 up: markup-carve/carve#1917 moved the bare body onto this
     * answer and left the nested one behind, filed as
     * markup-carve/carve#1918.
     *
     * @return array<string, array{0: int}>
     */
    public static function nestedListBandProvider(): array
    {
        return [
            'column 0' => [0],
            'at the body column' => [3],
            'past the body column, below the item' => [4],
            'at the item column' => [5],
            'one past the item column' => [6],
            'two past the item column' => [7],
        ];
    }

    #[DataProvider('nestedListBandProvider')]
    public function testTheReferenceDefinitionBand(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(self::ENDED, trim($html));
    }

    /**
     * THE OTHER DEFINITION SPELLING answers the same band, because the two
     * share the one predicate this collector asks.
     */
    #[DataProvider('nestedListBandProvider')]
    public function testTheFootnoteDefinitionBand(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "[^f]: x\ntail");

        $this->assertSame(self::ENDED, trim($html));
    }

    /**
     * THE SEPARATOR SETS THE COLUMNS, so the whole band moves with it rather
     * than sitting at a constant. A one-space separator puts the body at 2 and
     * the item at 4, and column 2 - below the column in the band above - is at
     * it here. Without this row the band is satisfied by any rule that happens
     * to agree at 3.
     *
     * @return array<string, array{0: int}>
     */
    public static function narrowSeparatorBandProvider(): array
    {
        return [
            'column 0' => [0],
            'at the body column' => [2],
            'past the body column, below the item' => [3],
            'at the item column' => [4],
            'one past the item column' => [5],
            'two past the item column' => [6],
        ];
    }

    #[DataProvider('narrowSeparatorBandProvider')]
    public function testTheSeparatorWidthMovesTheBand(int $column): void
    {
        $html = $this->converter->convert(":: t\n: - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(self::ENDED, trim($html));
    }

    /**
     * THE LIST NEED NOT SIT ON THE BODY'S MARKER LINE. Opened below the lead,
     * after the body's own paragraph, it asks the same question of the same
     * columns, and it is the spelling the marker-lead reading cannot reach by
     * accident.
     *
     * Matches carve-js at every column. The carve-rs binary agrees except at
     * the between-columns row, which is exactly its `441` gap above.
     */
    #[DataProvider('nestedListBandProvider')]
    public function testTheListMayOpenBelowTheLead(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  x\n\n   - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>x</p>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * THE DEFINITION REGISTERS, which is the half the rendered band cannot
     * show: an unregistered label leaves `[r][]` literal, and a body that read
     * the line as prose would print it too.
     */
    public function testADefinitionPastTheColumnBothEndsTheBodyAndRegisters(): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n    [r]: /url\ntail\n\nSee [r][].");

        $this->assertSame(
            self::ENDED . "\n<p>See <a href=\"/url\">r</a>.</p>",
            trim($html),
        );
    }

    /**
     * THE ITEM KEEPS COLLECTING AT ITS OWN COLUMN. This is what says the nested
     * column is read rather than the body's alone: at column 5 the definition
     * is the ITEM'S, so the item's later line at the same column stays inside
     * it. Erasing the residual indentation here instead would end the list and
     * leave `b` as a paragraph in the body.
     *
     * Matches carve-js, carve-rs and the executable spec, and is byte-identical
     * before and after this change.
     */
    public function testTheItemKeepsCollectingAtItsOwnColumn(): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n     [r]: /url\n     b");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        b\n      </li>\n    </ul>\n  </dd>\n</dl>",
            trim($html),
        );
    }

    /**
     * ONE COLUMN SHALLOWER THE BODY OWNS IT, so the item ends there and the
     * line below at the item's old column is the body's paragraph. The pair
     * with the row above is the whole nested-column rule, stated on the bytes.
     *
     * Matches carve-js; the executable spec and carve-rs fold the whole run.
     */
    public function testTheBodyOwnsItOneColumnShallower(): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n    [r]: /url\n     b");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>b</p>\n  </dd>\n</dl>",
            trim($html),
        );
    }

    /**
     * AN OPAQUE BODY IS NOT ASKED THE QUESTION. Inside a code fence the line is
     * verbatim content and the indentation past the body's column is part of
     * it, so neither half of this change may touch it. The nested column cannot
     * refuse it - a fence opens no content column, so it answers 0, which is
     * the erasing answer - and without the opaque guard a leading space
     * disappeared out of the code block (raised by codex review).
     *
     * Byte-identical before and after, and matches carve-js and both spec
     * revisions. The carve-rs binary reads this shape differently, before and
     * after alike.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function fencedBodyProvider(): array
    {
        return [
            'one past the column' => [4, ' [r]: /url'],
            'two past the column' => [5, '  [r]: /url'],
        ];
    }

    #[DataProvider('fencedBodyProvider')]
    public function testAFencedBodyKeepsItsIndentation(int $column, string $code): void
    {
        $html = $this->converter->convert(":: t\n:  ```\n" . str_repeat(' ', $column) . "[r]: /url\n   ```");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>" . $code . "\n</code></pre>\n  </dd>\n</dl>",
            trim($html),
        );
    }

    /**
     * A FENCE OPENED BELOW THE BODY'S OWN PROSE, with no blank line, is the
     * spelling the guard above cannot see with a closer lookahead: the closer
     * is still ahead of the collected entries, so a fold that looks for it
     * reads the opener as an unterminated fence and arms nothing. The probe
     * therefore asks without the lookahead, and this row is what says so - it
     * lost a leading space out of the code block otherwise.
     *
     * Byte-identical before and after, and matches carve-js.
     */
    #[DataProvider('fencedBodyProvider')]
    public function testAFenceBelowTheBodysProseKeepsItsIndentation(int $column, string $code): void
    {
        $html = $this->converter->convert(":: t\n:  prose\n   ```\n" . str_repeat(' ', $column) . "[r]: /url\n   ```\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>prose</p>\n    <pre><code>" . $code
                . "\n</code></pre>\n  </dd>\n</dl>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * BELOW THE BODY'S COLUMN THIS ENGINE STILL STANDS ALONE, and these rows
     * PIN THAT DIVERGENCE rather than an agreement. carve-js, carve-rs and the
     * executable spec all fold the definition into the item as text; this
     * engine ends the body and prints the line at document level. §24 C3 backs
     * the other three, so this is a separate defect one column band down -
     * filed as carve-php#1875 and pinned here so it fails loudly when it lands.
     *
     * Byte-identical before and after this change.
     *
     * @return array<string, array{0: int}>
     */
    public static function belowColumnBandProvider(): array
    {
        return [
            'below the column, 1' => [1],
            'below the column, 2' => [2],
        ];
    }

    #[DataProvider('belowColumnBandProvider')]
    public function testBelowTheBodysColumnTheBodyStillEnds(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>[r]: /url\ntail</p>",
            trim($html),
        );
    }

    /**
     * THE COMMENT CONTROL DOES NOT MOVE. §10 I5's column exemption already had
     * it end the paragraph at every column, so a change scoped to the two
     * definition kinds must leave the whole band alone.
     *
     * Byte-identical before and after, at every column from 0 to 7.
     *
     * @return array<string, array{0: int}>
     */
    public static function commentBandProvider(): array
    {
        return [
            'column 0' => [0],
            'below the column, 1' => [1],
            'below the column, 2' => [2],
            'at the body column' => [3],
            'past the body column, below the item' => [4],
            'at the item column' => [5],
            'one past the item column' => [6],
            'two past the item column' => [7],
        ];
    }

    #[DataProvider('commentBandProvider')]
    public function testTheCommentControlDoesNotMove(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  - a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame(self::ENDED, trim($html));
    }

    /**
     * THE ITEM HOST DOES NOT MOVE. The same document with a list item where the
     * description body was is out of this change's reach entirely, and its
     * whole band is byte-identical before and after.
     *
     * Rows 4 to 6 are a KNOWN DIVERGENCE and are pinned as one: this engine,
     * both spec revisions and carve-rs keep `tail` inside the outer item and
     * carve-js puts it outside. That is the item host's own open question, not
     * this ticket's band, and nothing here touches it.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function itemHostProvider(): array
    {
        $ended = "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $inside = "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n    tail\n  </li>\n</ul>";

        return [
            'column 0' => [0, $ended],
            'below the outer column' => [1, "<ul>\n  <li>\n    <ul>\n      <li>a\n[r]: /url\ntail</li>\n    </ul>\n  </li>\n</ul>"],
            'at the outer column' => [2, $ended],
            'past the outer column, below the inner' => [3, $ended],
            'at the inner column' => [4, $inside],
            'one past the inner column' => [5, $inside],
            'two past the inner column' => [6, $inside],
        ];
    }

    #[DataProvider('itemHostProvider')]
    public function testTheItemHostDoesNotMove(int $column, string $expected): void
    {
        $html = $this->converter->convert("- - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame($expected, trim($html));
    }
}
