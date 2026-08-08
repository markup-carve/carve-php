<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §8a, THE MARKDOWN TARGET'S ESCAPING NARROWS ON THE LINE
 * (markup-carve/carve#970).
 *
 * M1 is not one rule across the metacharacter set. It splits three ways:
 *
 * - **M1a** the ASTERISK keeps M1 unconditionally.
 * - **M1b** `_`, `#` and `[` are escaped IF AND ONLY IF the character is
 *   ADJACENT ON THE EMITTED LINE to an UNESCAPED DELIMITER OF THE SAME
 *   CHARACTER.
 * - **M1c** nothing else narrows.
 *
 * THE SHARP PAIR IS M1a AGAINST M1b, and it is what says a single-rule
 * implementation cannot satisfy both. Making the asterisk conditional kills only
 * the M1a cases; making the other three unconditional kills only the M1b cases.
 * One rule covering all four fails one half or the other.
 *
 * WHY ADJACENCY AND NOT SOMETHING WIDER. This target answers to a re-parser
 * Carve does not control - CommonMark, GFM, markdown-it with typographer,
 * pandoc - so dropping an escape is an argument owed once per reader. The
 * adjacency case owes none: every one of those readers resolves a delimiter by
 * RUN LENGTH, so an escape next to a live delimiter of the same character is
 * holding a run boundary apart under all of them at once. §8a is explicit that
 * M1b is an if-and-only-if rather than a floor to build a wider narrowing on.
 */
class MarkdownEscapingNarrowsOnTheLineTest extends TestCase
{
    protected function md(string $source): string
    {
        return trim(CarveConverter::markdown()->convert($source));
    }

    /**
     * M1b, the NOT-adjacent half. These are the documents the clause was raised
     * over: a backslash inside an identifier breaks exact-match search in the
     * published document and protects nothing.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bareProvider(): array
    {
        return [
            'underscore in an identifier' => ['company_id', 'company_id'],
            'several underscores' => ['read_write_delete', 'read_write_delete'],
            'trailing underscore' => ['trailing_', 'trailing_'],
            'leading underscore' => ['_leading', '_leading'],
            'hash after a letter' => ['C#', 'C#'],
            'hash opening a word' => ['issue #123', 'issue #123'],
            'bracket alone' => ['a [b c', 'a [b c'],
            'bracket at the end' => ['see [', 'see ['],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('bareProvider')]
    public function testANonAdjacentCandidateIsEmittedBare(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * M1b, the ADJACENT half. Unescaping would MERGE THE TWO INTO ONE RUN, which
     * every reader this target answers to resolves by run length - so both
     * escapes are kept.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adjacentProvider(): array
    {
        return [
            'two underscores' => ['a __b', 'a \_\_b'],
            'two hashes' => ['a ##b', 'a \#\#b'],
            'two brackets' => ['a [[b', 'a \[\[b'],
            'three underscores' => ['a ___b', 'a \_\_\_b'],
            'underscores around a word' => ['a __b__ c', 'a \_\_b\_\_ c'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('adjacentProvider')]
    public function testAnAdjacentCandidateKeepsItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * M1a. The asterisk is not a character that MIGHT meet markup on the line -
     * it is the character the line's markup is made of, because this writer
     * spells emphasis with it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function asteriskProvider(): array
    {
        return [
            'lone asterisk' => ['a *b', 'a \*b'],
            'asterisk in an identifier position' => ['a*b*c', 'a\*b\*c'],
            'trailing asterisk' => ['trailing*', 'trailing\*'],
            'asterisk alone on the line' => ['*', '\*'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('asteriskProvider')]
    public function testTheAsteriskKeepsM1Unconditionally(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * THE CASE THE EXEMPTION EXISTS FOR. Emphasis whose content is two literal
     * asterisks. Under M1 it is written `*\*\**`; a narrowing that weighed the
     * delimiter runs saw one four-character run flanking nothing and dropped
     * both escapes, giving `****` - and through a CommonMark reader those are
     * not two spellings of one document:
     *
     *     *\*\** -> <p><em>**</em></p>
     *     **** -> <hr />
     *
     * Emphasis containing two asterisks published as a thematic break. The run
     * being weighed was partly the writer's OWN delimiters, which the escaped
     * literals had merged into.
     *
     * @return void
     */
    public function testEmphasisContainingTwoAsterisksIsNotPublishedAsAThematicBreak(): void
    {
        $this->assertSame('*\*\**', $this->md('/**/'));
        $this->assertNotSame('****', $this->md('/**/'));
    }

    /**
     * M2, untouched by §8a. An `escaped_text` node is emitted AS AN ESCAPE
     * whatever the character: M1 governs a character that reached the writer
     * inside a TEXT node, one the author did NOT mark, and this is the other
     * case. The line test never sees it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredEscapeProvider(): array
    {
        return [
            'authored underscore' => ['a\_b', 'a\_b'],
            'authored hash' => ['\#h', '\#h'],
            'authored bracket' => ['\[x', '\[x'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('authoredEscapeProvider')]
    public function testAnAuthoredEscapeIsEmittedAsAnEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * The two spellings are no longer one document on this target, which is the
     * consequence of M2 outranking the line test and is worth stating rather
     * than leaving to be discovered.
     *
     * @return void
     */
    public function testAnAuthoredEscapeAndABareCharacterDiffer(): void
    {
        $this->assertSame('a\_b', $this->md('a\_b'));
        $this->assertSame('a_b', $this->md('a_b'));
    }

    /**
     * A neighbour BEFORE the candidate counts only if it is not itself behind a
     * backslash. An AFTER neighbour never can be - the character in front of it
     * is the candidate.
     *
     * @return void
     */
    public function testANeighbourBehindABackslashIsNotALiveDelimiter(): void
    {
        // The authored escape puts a real backslash on the line, and the text
        // underscore that follows it is therefore not beside a LIVE delimiter.
        $this->assertSame('a\__b', $this->md('a\__b'));
    }

    /**
     * A candidate is decided against the line as it reads if NOTHING is escaped,
     * not against a half-rewritten one. Deciding left to right against the
     * output being built would let the first candidate's own backslash change
     * the answer for the second.
     *
     * @return void
     */
    public function testCandidatesAreDecidedAgainstTheUnescapedLine(): void
    {
        $this->assertSame('a \_\_b', $this->md('a __b'));
    }

    /**
     * CONTROL for the P1 the spec side caught. The guard is now a hand-written
     * class rather than `\p{Cc}`, because PART 9 §29 T2 has this target EMIT the
     * non-whitespace C0 controls - so U+0001 has moved out of this list and into
     * MarkdownAndPlainEmitTheC0ControlsTest.
     *
     * What the narrowing must NOT reach is DEL (U+007F) and U+0080-U+009F. CSI
     * (U+009B) and OSC (U+009D) are SINGLE-CHARACTER forms of the very sequences
     * PART 9 §25's terminal rule exists to stop, and §29 T5 puts them outside
     * that section on purpose. Nothing §25 blocks becomes emittable through §8a
     * or through §29.
     *
     * @return void
     */
    public function testNothingSection25BlocksBecomesEmittableControl(): void
    {
        foreach (["\u{009B}", "\u{009D}", "\u{007F}", "\u{0080}", "\u{009F}"] as $control) {
            $rendered = CarveConverter::markdown()->convert('a' . $control . 'b');

            $this->assertStringNotContainsString($control, $rendered);
            $this->assertStringContainsString('ab', $rendered);
        }
    }

    /**
     * CONTROL. The sentinels this renderer uses to defer the decision must never
     * survive into the output, and author content carrying one must not be read
     * as an escape the renderer emitted.
     *
     * @return void
     */
    public function testTheSentinelsNeverReachTheOutputControl(): void
    {
        foreach (["\u{E004}", "\u{E005}", "\u{E006}"] as $sentinel) {
            $rendered = CarveConverter::markdown()->convert('a' . $sentinel . '_b_');

            $this->assertStringNotContainsString($sentinel, $rendered);
        }
    }

    /**
     * CONTROL. Regions this renderer reproduces byte-exact must not be rewritten:
     * a backslash there is content, not an escape.
     *
     * @return void
     */
    public function testVerbatimRegionsAreUntouchedControl(): void
    {
        $this->assertSame('`a_b`', $this->md('`a_b`'));
        $this->assertSame('`a\_b`', $this->md('`a\_b`'));
    }
}
