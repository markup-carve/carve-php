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
 * - **M1c** a paragraph line must not become a list.
 * - **M1d** nothing else narrows.
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
    public function testParagraphLinesCannotBecomeListsInMarkdownReaders(): void
    {
        $converter = CarveConverter::markdown();
        $this->assertSame("para\n\\- tail\n", $converter->convert("para\n- tail\n"));
        $this->assertSame("para\n\\+ tail\n", $converter->convert("para\n+ tail\n"));
        $this->assertSame("para\n1\\. tail\n", $converter->convert("para\n1. tail\n"));
        $this->assertSame("para\n1\\) tail\n", $converter->convert("para\n1) tail\n"));
        $this->assertSame("- real\n", $converter->convert("- real\n"));
        $this->assertSame("para `code\n- literal`\n", $converter->convert("para ``code\n- literal``\n"));
    }

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
     * M2 as §8b leaves it. An `escaped_text` node whose character COULD be read
     * as markup keeps its escape, whether or not the line test would have kept
     * it: `_` and `[` can pair or open a link anywhere, so neither narrows.
     *
     * The hash moved to §8b M2b and is covered below, since its reading is
     * positional rather than a property of the character.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredEscapeProvider(): array
    {
        return [
            'authored underscore' => ['a\_b', 'a\_b'],
            'authored bracket' => ['\[x', '\[x'],
            'authored asterisk' => ['a\*b', 'a\*b'],
            'authored smart-punctuation trigger' => ['a\-\- b', 'a\-\- b'],
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
     * §8b M2a. An authored escape of a character this target's readers never
     * read as markup is emitted BARE, at any position.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredInertProvider(): array
    {
        return [
            'a mention opener' => ['hi \@user ok', 'hi @user ok'],
            'a brace' => ['a \{x b', 'a {x b'],
            'a caret' => ['a \^x b', 'a ^x b'],
            'a percent' => ['a \%x b', 'a %x b'],
            'a colon' => ['a \:x b', 'a :x b'],
            'a slash' => ['a \/x b', 'a /x b'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('authoredInertProvider')]
    public function testAnAuthoredEscapeOfAnInertCharacterIsEmittedBare(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * §8b M2b. The hash is read as markup only where it would OPEN AN ATX
     * HEADING, which is the line's content position plus a run of one to six
     * closed by a space, a tab or the end of the line.
     *
     * `\#tag` at a line's start is the case that separates the position test
     * from the run test: it stands at the content position and still opens
     * nothing, because no space closes the run.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredHashProvider(): array
    {
        return [
            'mid-line' => ['a \#y b', 'a #y b'],
            'an issue reference' => ['issue \#123 fixed', 'issue #123 fixed'],
            'a language name' => ['C\# is a language', 'C# is a language'],
            'a hex colour' => ['Bau \#64748b', 'Bau #64748b'],
            'at a line start, no space closing the run' => ['\#tag rest', '#tag rest'],
            'at a line start with a space' => ['\# heading', '\# heading'],
            // ESCAPING THE FIRST HASH ALONE IS SUFFICIENT, which is M1e's
            // argument about `<` one character out: a heading that cannot open
            // needs nothing done to the rest of its run. The second and third
            // hashes are not at the content position, so M2b emits them bare
            // and the line still reads as text.
            'at a line start, a run of three' => ['\#\#\# heading', '\### heading'],
            'after a parenthesis' => ['see (\#tag) there', 'see (#tag) there'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('authoredHashProvider')]
    public function testAnAuthoredHashIsDecidedByItsPosition(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->md($source));
    }

    /**
     * BOUND: a run of SEVEN hashes is not an ATX heading in any flavour, so the
     * escape protects nothing and the run is emitted bare.
     *
     * @return void
     */
    public function testARunOfSevenHashesIsNotAHeading(): void
    {
        $this->assertSame('####### x', $this->md('\#\#\#\#\#\#\# x'));
    }

    /**
     * BOUND: narrowing M2 does not reach a code span, where a backslash is
     * content the writer must reproduce byte-exact.
     *
     * @return void
     */
    public function testACodeSpanKeepsItsOwnBackslash(): void
    {
        $this->assertSame('`a \# b`', $this->md('`a \# b`'));
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
     * The sentinels this renderer uses to defer the decision must never survive
     * into the output, and AUTHOR CONTENT AT THOSE CODE POINTS MUST.
     *
     * This row used to say the opposite, and it was pinning the defect rather
     * than the rule: the sentinels were the fixed U+E004..U+E006 and author
     * content was kept off them by DELETING the range on the way in, so `a`
     * U+E004 `b` came out `ab`. PART 7 makes those characters content and PART 9
     * section 29 says this target emits content it did not expect rather than
     * deleting it, so the deletion was the lossy answer that section rejects
     * (markup-carve/carve-php#1087).
     *
     * The sentinels are chosen per document now, so both halves hold at once:
     * whatever run the renderer picked is absent from the output BECAUSE it is
     * absent from the document, and the author's own character is emitted. The
     * `_b_` is there so a run that abandoned the sentinel mechanism entirely
     * could not pass: the underline still has to be decided on the line, and it
     * decides DIFFERENTLY with the character present - `a_b_` is intraword and
     * stays literal, while `a<U+E004>_b_` is not and becomes `<u>`. A renderer
     * that swallowed the character would emit the control row's answer.
     *
     * @return void
     */
    public function testAnAuthoredSentinelCodePointSurvivesAndTheEscapeStillDecides(): void
    {
        foreach (["\u{E004}", "\u{E005}", "\u{E006}"] as $character) {
            $rendered = CarveConverter::markdown()->convert('a' . $character . '_b_');

            $this->assertSame('a' . $character . "<u>b</u>\n", $rendered);
        }
    }

    /**
     * CONTROL for the row above: the same documents with the character removed.
     *
     * @return void
     */
    public function testTheSameDocumentWithoutTheCharacterIsUnchangedControl(): void
    {
        $this->assertSame("a_b_\n", CarveConverter::markdown()->convert('a_b_'));
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
