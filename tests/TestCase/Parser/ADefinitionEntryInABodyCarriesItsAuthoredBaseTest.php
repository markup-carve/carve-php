<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24 C3 gives a recognized block opener AT OR PAST a definition body's
 * column 3 or a footnote body's column 2 an authored local `block_base`, and
 * that base has to survive the blank that separates a definition entry from its
 * payload. Written ONE column past the body minimum, the `::` line's own column
 * becomes the entry's base and the payload keeps its offset from it - so the
 * payload reaches the description and nests in the `dd`.
 *
 * THE CLAUSE NAMES ONLY THOSE TWO BODIES. A LIST ITEM IS OUTSIDE IT, and is
 * pinned here alongside them on purpose. carve#1752 asks a payload to keep its
 * offset from its opener, and in a list item both spellings already carry the
 * same offset - so both say the same thing and neither nests. carve-js first
 * wrote an arm that was right for one container and applied it to all of them,
 * which traded this defect for its mirror image (carve-js#1514, fixed in
 * carve-js#1520). Pinning both sides is what stops a later change from
 * repairing one and drifting the other.
 *
 * The expectations are the executable spec oracle's, which reproduces the whole
 * corpus byte for byte; corpus category 419 pins the three footnote shapes.
 */
final class ADefinitionEntryInABodyCarriesItsAuthoredBaseTest extends TestCase
{
    /**
     * The three bands, in each of the two bodies the clause names.
     *
     * @return array<string, array{string, bool, bool}>
     */
    public static function inClauseProvider(): array
    {
        return [
            // A raised entry whose payload sits at the description's own column.
            'footnote body, entry raised, payload at its column' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n      > quote\n\nsee[^n]\n",
                true,
                false,
            ],
            'definition body, entry raised, payload at its column' => [
                ":: outer\n:  intro\n\n    :: term\n    :  definition\n\n       > quote\n",
                true,
                false,
            ],
            // At the body minimum there is no raise, so the payload never
            // reaches the description and stays a sibling of the list.
            'footnote body, entry at the minimum' => [
                "[^n]: intro\n\n  :: term\n  :  definition\n\n     > quote\n\nsee[^n]\n",
                false,
                false,
            ],
            'definition body, entry at the minimum' => [
                ":: outer\n:  intro\n\n   :: term\n   :  definition\n\n      > quote\n",
                false,
                false,
            ],
            // Raised, but the payload is written BELOW the description's column:
            // it reaches nothing and stays the literal text it was written as.
            'footnote body, entry raised, payload below its column' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n     > quote\n\nsee[^n]\n",
                false,
                true,
            ],
            'definition body, entry raised, payload below its column' => [
                ":: outer\n:  intro\n\n    :: term\n    :  definition\n\n      > quote\n",
                false,
                true,
            ],
        ];
    }

    #[DataProvider('inClauseProvider')]
    public function testABodyEntryCarriesItsAuthoredBase(string $source, bool $nested, bool $literal): void
    {
        $html = (new CarveConverter())->convert($source);

        if ($literal) {
            self::assertStringContainsString('&gt; quote', $html);
            self::assertStringNotContainsString('<blockquote>', $html);

            return;
        }

        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $html);
        self::assertSame($nested, $this->quoteIsInsideTheDescription($html), $html);
    }

    /**
     * A LIST ITEM IS OUTSIDE THE CLAUSE and keeps its own answer.
     *
     * Both spellings carry the same offset from the `::`, so both say the same
     * thing: the quote is a sibling of the list in either one.
     */
    public function testAListItemEntryKeepsItsOwnAnswer(): void
    {
        $converter = new CarveConverter();
        $minimum = $converter->convert("- intro\n\n  :: term\n  :  definition\n\n     > quote\n");
        $raised = $converter->convert("- intro\n\n   :: term\n   :  definition\n\n      > quote\n");

        self::assertSame($minimum, $raised);
        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $raised);
        self::assertFalse($this->quoteIsInsideTheDescription($raised), $raised);
    }

    /**
     * A raised entry with NO payload after its blank is the same document at
     * either column, in every host. The base only becomes observable once
     * something follows the blank.
     */
    public function testAnEntryWithNoPayloadIsTheSameDocumentAtEitherColumn(): void
    {
        $converter = new CarveConverter();
        $hosts = [
            'footnote body' => ["[^n]: intro\n\n%s:: term\n%s:  definition\n\nsee[^n]\n", 2],
            'definition body' => [":: outer\n:  intro\n\n%s:: term\n%s:  definition\n", 3],
            'list item' => ["- intro\n\n%s:: term\n%s:  definition\n", 2],
        ];

        foreach ($hosts as $name => [$template, $minimum]) {
            $pad = str_repeat(' ', $minimum);
            $exact = sprintf($template, $pad, $pad);
            $over = sprintf($template, $pad . ' ', $pad . ' ');
            self::assertSame($converter->convert($exact), $converter->convert($over), $name);
        }
    }

    /**
     * Is the `> quote` payload inside the INNER description, or a sibling of
     * the list that holds it?
     */
    private function quoteIsInsideTheDescription(string $html): bool
    {
        $quote = strpos($html, '<blockquote>');
        if ($quote === false) {
            return false;
        }
        $close = strpos($html, '</dl>');

        return $close !== false && $quote < $close;
    }
}
