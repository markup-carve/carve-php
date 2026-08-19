<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `markerContentOffset()` answers exactly what `parseListItemMarker()` answers.
 *
 * The trailing-block tracker walks the markers on a line to find the innermost
 * content, and asking the parser for each of them copies the whole remaining
 * line every time it matches - so the walk cost markers TIMES line length per
 * entry, and 8 KB of markers took seconds (carve-php#1426).
 *
 * The offset form removes the copy. What it must NOT do is become a SECOND
 * spelling of the marker grammar: this repo already carries two spellings of
 * the marker prefix that disagree with each other, and a wrong content offset
 * in this path would silently change how ordinary documents parse. Both forms
 * are therefore rendered from ONE set of heads - the capture and the lookahead
 * are the same string with a different tail - and this test is the proof that
 * they agree, over every marker shape the parser recognizes and every one it
 * deliberately refuses.
 *
 * THE ORACLE IS THE PARSER ITSELF, not a table of expected offsets. A table
 * would encode the same reading twice and pass on a shared mistake; the parser
 * is what every other call site uses, so agreement with it IS the property.
 */
class MarkerContentOffsetAgreesWithTheParserTest extends TestCase
{
    /**
     * Marker spellings: every accepted branch, plus the refusals that make the
     * branches separable - a roman numeral that fails `romanToInt()` falling
     * through to alpha, a multi-letter alpha that is not a marker at all, and
     * the abutting attribute block with a valid, an empty and an invalid
     * payload.
     *
     * @return array<string>
     */
    private static function markers(): array
    {
        return [
            '-', '*', '+', '.', '..',
            '1.', '1)', '0.', '12.', '007)',
            'a.', 'A)', 'z.', 'q.', 'ab.', 'ab)',
            'i.', 'I)', 'iv.', 'vi)', 'x.', 'mmm.', 'iiii.', 'viv.',
            '- [x]', '- [ ]', '* [X]', '- [_]', '- [!]', '- [xx]',
            '-{.k}', '-{}', '-{.a .b}', '-{bad ..}', '1.{.k}', 'ab.{.k}', '.{#i}', 'iv.{.k}',
            // The attribute strip spells its bullets as a literal `[-*]`, which
            // the plus-bullet extension does not widen - so `+{.k}` is not a
            // marker even with the extension on.
            '+{.k}', '*{.k}', '+{}',
            '::', ':', '>', '#', 'text',
        ];
    }

    /**
     * Tails, including the ones that make a marker NOT a marker: no space, no
     * content, whitespace-only content, and a tab where only a space is
     * accepted.
     *
     * @return array<string>
     */
    private static function tails(): array
    {
        return [
            '', ' ', '  ', "\t", "\tx", ' x', '  x', '   x', 'x',
            // A TAB AFTER THE SPACES is where the two forms first disagreed:
            // the abutting-attribute strip accepts only ` +NON_WHITESPACE`
            // after the block, while a plain head's gap would take the tab.
            " \tx", "  \tx", " \t x", " \t- y",
            ' [x] y', ' [ ] y', ' [!] y', ' - y', ' 1. y', ' *b* c', ' {.k}', ' }', ' %% c',
        ];
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function bulletModeProvider(): array
    {
        return ['default bullets' => [false], 'with the plus bullet' => [true]];
    }

    #[DataProvider('bulletModeProvider')]
    public function testTheOffsetIsWhereTheParsedContentBegins(bool $plusBullet): void
    {
        $parser = new ListParser();
        $parser->allowPlusBullet($plusBullet);

        $checked = 0;
        foreach (self::markers() as $marker) {
            foreach (self::tails() as $tail) {
                foreach (['', ' ', 'zz '] as $lead) {
                    $line = $lead . $marker . $tail;
                    $info = $parser->parseListItemMarker($line);
                    // The content is always a SUFFIX of the line it was parsed
                    // from, including through the attribute rewrite, which
                    // removes a middle chunk the content comes after.
                    $expected = $info === null
                        ? null
                        : strlen($line) - strlen($info['content']);

                    $this->assertSame(
                        $expected,
                        $parser->markerContentOffset($line),
                        'disagreed on ' . var_export($line, true),
                    );
                    $checked++;
                }
            }
        }

        // The matrix is the test; a provider that silently shrank to nothing
        // would pass every assertion above.
        $this->assertGreaterThan(2000, $checked);
    }

    /**
     * The WALK, which is what the tracker actually does: take the innermost
     * content of a line of nested markers, by offsets and by strings, and
     * require the same answer.
     */
    #[DataProvider('bulletModeProvider')]
    public function testTheWalkFindsTheSameInnermostContent(bool $plusBullet): void
    {
        $parser = new ListParser();
        $parser->allowPlusBullet($plusBullet);

        // ALTERNATING PAIRS, not a repeat of one spelling. A 44,100-document
        // sweep found a disagreement that shows only where `\t- ` sits beside
        // `-{.k} `, and a uniform repeat of either one passes.
        $markers = ['- ', '* ', '+ ', '1. ', 'a. ', 'iv. ', '. ', '-{.k} ', '+{.k} ', '- [x] ', "\t- ", '1.{#i} '];
        $pairs = [];
        foreach ($markers as $first) {
            foreach ($markers as $second) {
                $pairs[] = $first . $second;
            }
        }

        foreach ($pairs as $marker) {
            foreach ([1, 2, 4] as $depth) {
                foreach (['x', '> q', '%% c', '# h', '``` ', '1. y'] as $innermost) {
                    $line = str_repeat($marker, $depth) . $innermost;

                    $byStrings = null;
                    $info = $parser->parseListItemMarker($line);
                    while ($info !== null) {
                        $byStrings = $info['content'];
                        $info = $parser->parseListItemMarker($byStrings);
                    }

                    $offset = $parser->innermostMarkerContentOffset($line);
                    $byOffsets = $offset === null ? null : substr($line, $offset);

                    $this->assertSame(
                        $byStrings,
                        $byOffsets,
                        'walk disagreed on ' . var_export($line, true),
                    );
                }
            }
        }
    }

    /**
     * AN INTERIOR NEWLINE IS THE ONE SHAPE THE FAST FORM WOULD MISREAD.
     *
     * `parseListItemMarker()` answers null for it - its tail cannot cross a
     * newline - so the walk screens for one and answers null too. Asserted
     * because the lookahead the walk matches with drops that tail on purpose,
     * and this is the difference dropping it makes.
     */
    public function testAnInteriorNewlineIsScreenedRatherThanMisread(): void
    {
        $parser = new ListParser();

        foreach (["- a\nb", "- - a\nb", "- a\nb\nc", "1. a\n- b"] as $subject) {
            $this->assertNull($parser->parseListItemMarker($subject));
            $this->assertNull($parser->innermostMarkerContentOffset($subject));
        }

        // A TRAILING newline is not an interior one, and the parser reads it,
        // so the walk must too.
        $this->assertSame('a', $parser->parseListItemMarker("- a\n")['content'] ?? null);
        $this->assertSame(2, $parser->innermostMarkerContentOffset("- a\n"));
    }

    /**
     * THE MEMOIZED PATTERNS FOLLOW THE BULLET CLASS.
     *
     * Both renderings are built once and kept, so enabling the plus bullet
     * AFTER the tables are warm has to throw them away. Warmed first on
     * purpose: called before any parse, a stale table is invisible, and the
     * extension is not obliged to be configured before the first document.
     */
    public function testEnablingThePlusBulletAfterTheTablesAreWarmStillTakesEffect(): void
    {
        $parser = new ListParser();

        $this->assertNull($parser->markerContentOffset('+ x'));
        $this->assertNull($parser->parseListItemMarker('+ x'));

        $parser->allowPlusBullet();

        $this->assertSame(2, $parser->markerContentOffset('+ x'));
        $this->assertSame('x', $parser->parseListItemMarker('+ x')['content'] ?? null);

        $parser->allowPlusBullet(false);

        $this->assertNull($parser->markerContentOffset('+ x'));
        $this->assertNull($parser->parseListItemMarker('+ x'));
    }

    /**
     * THE CONTROL: the two forms are not trivially equal because both return
     * null. A marker line has an offset, and a non-marker line does not.
     */
    public function testTheTwoAnswersAreNotBothAlwaysNull(): void
    {
        $parser = new ListParser();

        $this->assertSame(2, $parser->markerContentOffset('- x'));
        $this->assertNull($parser->markerContentOffset('text'));
    }
}
