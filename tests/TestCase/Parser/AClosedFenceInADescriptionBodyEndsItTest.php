<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A CLOSED fence ends a description body, so a flush-left line below it is the
 * document's and not the body's.
 *
 * markup-carve/carve#1930, ported as carve-php#1899. `A FENCED BODY IS NOT A
 * PARAGRAPH` and `FENCE KIND DOES NOT DETERMINE CONTAINER REACH` are both
 * NORMATIVE: section 4's lazy branch needs an OPEN paragraph, and a closed
 * fence body is not one. Corpus 270 and 86 pin the list-item host the same way.
 *
 * THE DEFECT WAS IN THE BODY'S TRACKER, not in the fold. An opener written PAST
 * the body's column is rebased so the tracker reads it at column 0, but its
 * CLOSER is not an opener, so it arrived still indented and matched nothing.
 * The block never closed, the body reported a paragraph it does not have, and
 * `tail` folded into the `dd`. The closer - and the section 10 closer lookahead
 * that decides whether a code fence arms at all - now read at the same base.
 *
 * THE BAND CANNOT BE SPLIT. `descriptionBodyEntryAsRead()` rebases before the
 * fold question is asked, so a fence AT the body's column and one PAST it reach
 * the predicate as identical lines; both are pinned below for that reason.
 *
 * Measured against the executable spec at markup-carve/carve `063656e7`. The
 * c1/c2 band - a fence BELOW the body's column but not at document column 0 -
 * is out of scope for this ruling and is deliberately absent from the provider.
 */
class AClosedFenceInADescriptionBodyEndsItTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The body's content column is 3 here, so 3 is AT it and 4 to 6 are PAST
     * it. All three fence kinds, because the reach rule does not read the kind.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function closedFenceProvider(): array
    {
        $rows = [];
        foreach (['div' => ['::: note', ':::'], 'code' => ['```', '```'], 'comment' => ['%%%', '%%%']] as $kind => $pair) {
            foreach ([3, 4, 5, 6] as $column) {
                $rows["a closed {$kind} fence at column {$column}"] = [$pair[0], $pair[1], $column];
            }
        }

        return $rows;
    }

    /**
     * `tail` is a document-level paragraph: it sits after the `</dl>`.
     */
    #[DataProvider('closedFenceProvider')]
    public function testAClosedFenceEndsTheBody(string $open, string $close, int $column): void
    {
        $pad = str_repeat(' ', $column);
        $html = $this->converter->convert(
            ":: term\n:  definition\n{$pad}{$open}\n{$pad}body\n{$pad}{$close}\ntail\n",
        );

        $this->assertMatchesRegularExpression('~</dl>\s*<p>tail</p>~', $html);
    }

    /**
     * THE UNTERMINATED TWIN KEEPS THE OLD ANSWER, and is the control that says
     * this ruling is about a CLOSED fence rather than about fences. An opener
     * with no closer opens no block, so the paragraph above it really is still
     * open and `tail` folds into the body.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function unterminatedFenceProvider(): array
    {
        $rows = [];
        foreach (['div' => '::: note', 'code' => '```', 'comment' => '%%%'] as $kind => $open) {
            foreach ([3, 4, 5, 6] as $column) {
                $rows["an unterminated {$kind} fence at column {$column}"] = [$open, $column];
            }
        }

        return $rows;
    }

    #[DataProvider('unterminatedFenceProvider')]
    public function testAnUnterminatedFenceLeavesTheBodyOpen(string $open, int $column): void
    {
        $pad = str_repeat(' ', $column);
        $html = $this->converter->convert(
            ":: term\n:  definition\n{$pad}{$open}\n{$pad}body\ntail\n",
        );

        $this->assertDoesNotMatchRegularExpression('~</dl>\s*<p>tail</p>~', $html);
    }

    /**
     * THE SEPARATOR MOVES THE COLUMN WITH IT, so the band is not satisfied by a
     * rule that happens to agree at 3. A one-space separator puts the body at 2,
     * where column 3 is PAST rather than AT.
     */
    public function testTheSeparatorWidthMovesTheBand(): void
    {
        $html = $this->converter->convert(":: term\n: definition\n   ::: note\n   body\n   :::\ntail\n");

        $this->assertMatchesRegularExpression('~</dl>\s*<p>tail</p>~', $html);
    }
}
