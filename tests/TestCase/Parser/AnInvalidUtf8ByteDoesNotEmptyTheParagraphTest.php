<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Util\StringUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 1 TAKES THE INPUT AS UTF-8, AND SAYS NOTHING ABOUT A BYTE THAT IS NOT.
 *
 * All three engines answered that differently and all three answers are
 * defensible on their own: carve-rs refuses the document and says why, carve-js
 * decodes lossily and keeps every valid character, and carve-php rendered
 * `<p></p>` with exit 0 and an empty stderr. Only the third one DESTROYS VALID
 * CONTENT WHILE REPORTING SUCCESS, which is the single outcome a caller has no
 * way to detect (markup-carve/carve-php#1082).
 *
 * The source is now decoded the way carve-js decodes it: one U+FFFD per maximal
 * ill-formed subsequence, everything else untouched.
 *
 * WHAT THE DEFECT ACTUALLY WAS, because "the parser dropped it" is wrong and
 * would send the next reader to the wrong file. The parse was FINE - the text
 * node carried all thirteen bytes. The loss was at the render exit, twice over,
 * and neither site is a strip:
 *
 * - `htmlspecialchars()` without ENT_SUBSTITUTE returns the EMPTY STRING for
 *   the whole value when any byte is ill-formed, so the HTML target emptied.
 * - a `/u` pattern makes `preg_replace()` return null, and the C0 strip on the
 *   Markdown, plain and ANSI targets casts that back to `''`, so those three
 *   emptied through a different function on a different line.
 *
 * One malformed byte, four targets, two unrelated mechanisms. That is why the
 * fix is at the door rather than at either site: a target added tomorrow would
 * have found a third way to answer nothing.
 *
 * ASSERT ON THE BYTES. U+FFFD is a printable character and the ill-formed byte
 * is invisible in a diff, so a rendered-string eyeball comparison cannot tell
 * the fixed output from the broken one.
 */
class AnInvalidUtf8ByteDoesNotEmptyTheParagraphTest extends TestCase
{
    /**
     * The exact document from the ticket: valid text on BOTH sides of one
     * ill-formed byte, with a paragraph before and after it.
     *
     * @var string
     */
    private const MIXED = "first para\n\nhello \x9b world\n\nlast para\n";

    /**
     * The same document with the byte removed.
     *
     * @var string
     */
    private const CONTROL = "first para\n\nhello  world\n\nlast para\n";

    public function testTheValidTextAroundAnIllFormedByteSurvivesOnTheHtmlTarget(): void
    {
        // The proving row. Before the fix this was
        // `<p>first para</p>\n<p></p>\n<p>last para</p>\n` - the eleven valid
        // characters around the byte gone, the two neighbours intact, exit 0.
        $this->assertSame(
            "<p>first para</p>\n<p>hello \u{FFFD} world</p>\n<p>last para</p>\n",
            (new CarveConverter())->convert(self::MIXED),
        );
    }

    public function testTheNeighbouringParagraphsAreUntouched(): void
    {
        // Pinned on purpose rather than left to the row above: refusing the
        // whole DOCUMENT because one paragraph is affected is a defensible
        // answer too (carve-rs picks it), but it is not the one taken here, and
        // it would have to be a deliberate choice rather than a side effect.
        $html = (new CarveConverter())->convert(self::MIXED);

        $this->assertStringContainsString('<p>first para</p>', $html);
        $this->assertStringContainsString('<p>last para</p>', $html);
    }

    public function testTheSameDocumentWithoutTheByteIsUnchanged(): void
    {
        // CONTROL. Passes before and after the fix and no mutation of this
        // defect touches it; it is here so the row above cannot be read as
        // "the renderer changed", only as "the ill-formed byte changed".
        $this->assertSame(
            "<p>first para</p>\n<p>hello  world</p>\n<p>last para</p>\n",
            (new CarveConverter())->convert(self::CONTROL),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function targets(): array
    {
        return [
            'html' => ['convert', "<p>hello \u{FFFD} world</p>\n"],
            'markdown' => ['markdown', "hello \u{FFFD} world\n"],
            'plain' => ['plainText', "hello \u{FFFD} world\n"],
            'ansi' => ['ansi', "hello \u{FFFD} world\n"],
        ];
    }

    #[DataProvider('targets')]
    public function testEveryTargetKeepsTheValidTextAroundTheByte(string $factory, string $expected): void
    {
        // Four targets, and before the fix all four answered nothing: the HTML
        // one produced `<p></p>` and the other three produced a bare newline.
        // Two different functions were responsible, which is the reason this
        // provider exists rather than one HTML row.
        $converter = $factory === 'convert' ? new CarveConverter() : CarveConverter::$factory();

        $this->assertSame($expected, $converter->convert("hello \x9b world\n"));
    }

    #[DataProvider('targets')]
    public function testEveryTargetIsUnchangedWithoutTheByte(string $factory, string $expected): void
    {
        // CONTROL for the row above, one per target.
        $converter = $factory === 'convert' ? new CarveConverter() : CarveConverter::$factory();
        $expected = str_replace("\u{FFFD}", '', $expected);

        $this->assertSame($expected, $converter->convert("hello  world\n"));
    }

    /**
     * Ill-formed inputs and the decode carve-js produces for each, measured
     * against `Buffer.from(bytes).toString('utf8')` on the same bytes.
     *
     * The `truncated three-byte sequence` row is the one that discriminates
     * between implementations: `\xE2\x82` is a valid PREFIX, so the WHATWG
     * decoder consumes both bytes as ONE maximal ill-formed subsequence and
     * emits ONE replacement character. A per-byte scan emits two, and
     * `htmlspecialchars(ENT_SUBSTITUTE)` disagrees on the surrogate row.
     * Neither is a substitute for the decoder, which is why they are pinned.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function illFormedSequences(): array
    {
        return [
            'C1 byte between valid text' => ["hello \x9b world", "hello \u{FFFD} world"],
            'truncated three-byte sequence' => ["a\xE2\x82b", "a\u{FFFD}b"],
            'truncated two-byte sequence' => ["\xC3", "\u{FFFD}"],
            'surrogate encoded as UTF-8' => ["\xED\xA0\x80", "\u{FFFD}\u{FFFD}\u{FFFD}"],
            'above U+10FFFF' => ["\xF4\x90\x80\x80", "\u{FFFD}\u{FFFD}\u{FFFD}\u{FFFD}"],
            'two stray bytes' => ["\xFF\xFE", "\u{FFFD}\u{FFFD}"],
        ];
    }

    #[DataProvider('illFormedSequences')]
    public function testTheDecodeMatchesCarveJsSequenceForSequence(string $input, string $expected): void
    {
        $this->assertSame($expected, StringUtil::toValidUtf8($input));
        $this->assertSame(1, preg_match('//u', StringUtil::toValidUtf8($input)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wellFormedSequences(): array
    {
        return [
            'ascii' => ['hello world'],
            'two-byte' => ["caf\u{00E9}"],
            'three-byte' => ["\u{4E2D}\u{6587}"],
            'four-byte' => ["\u{1F600}"],
            'private use' => ["a\u{E010}b"],
            'replacement character already present' => ["a\u{FFFD}b"],
            'empty' => [''],
        ];
    }

    #[DataProvider('wellFormedSequences')]
    public function testWellFormedInputIsReturnedByteForByte(string $input): void
    {
        // CONTROLS. The conversion is skipped entirely for well-formed input,
        // and these rows are what says so: any implementation that rewrote a
        // valid string - a normalization, a substitution character other than
        // U+FFFD, a dropped astral plane character - fails here rather than
        // silently shipping.
        $this->assertSame($input, StringUtil::toValidUtf8($input));
    }

    public function testTheTextNodeCarriesWellFormedBytesAfterParsing(): void
    {
        // The door is the parse entry, not the renderers, so the AST itself has
        // to be well-formed - otherwise every consumer of `parse()` (the AST
        // JSON codec, a host walking the tree, an extension) inherits the same
        // hazard from a different direction.
        $document = (new CarveConverter())->parse("hello \x9b world\n");
        $paragraph = $document->getChildren()[0];
        $text = $paragraph->getChildren()[0];

        $this->assertSame("hello \u{FFFD} world", $text->getContent());
    }

    public function testTheProcessGlobalSubstitutionCharacterIsLeftAsItWasFound(): void
    {
        // mbstring's substitution character is PROCESS-GLOBAL, so setting it is
        // a side effect on the host, not on this call. A library that parses one
        // document with a bad byte and leaves the global at U+FFFD has silently
        // changed what every later `mb_convert_encoding()` in the host process
        // does - including one in code that has nothing to do with Carve.
        //
        // Dropping the restore survives every other row here, which is exactly
        // what a global makes easy to miss: the value it is left at is the one
        // this code wants, so nothing downstream in the suite notices.
        $before = mb_substitute_character();
        mb_substitute_character(0x3F);

        (new CarveConverter())->convert("hello \x9b world\n");

        $restored = mb_substitute_character();
        mb_substitute_character($before);

        $this->assertSame(0x3F, $restored);
    }

    public function testThePositionTableIsMeasuredAgainstTheDecodedSource(): void
    {
        // WHERE the decode happens is load-bearing, and only this row says so.
        // PART 12 section 4 offsets are counted in CODE POINTS against the
        // source the position index was built from, and "how many code points"
        // has no answer for an ill-formed byte. Doing the decode after
        // `$this->originalSource` is assigned leaves the index counting the raw
        // bytes while every other reader sees the substituted text, and the two
        // disagree: the first paragraph came out `endOffset` 14 for thirteen
        // characters, and the SECOND paragraph - which contains nothing unusual
        // at all - came out `startOffset` 16 and `endColumn` 9 for a nine
        // character line.
        //
        // A mutation that moves the call down to `splitLines()` survives every
        // other row in this file, so without this one the placement would be
        // an accident rather than a decision.
        $ast = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse(self::MIXED));

        $this->assertSame(
            ['startLine' => 3, 'endLine' => 3, 'startColumn' => 1, 'endColumn' => 14, 'startOffset' => 12, 'endOffset' => 25],
            $ast['children'][1]['pos'],
        );
        $this->assertSame(
            ['startLine' => 5, 'endLine' => 5, 'startColumn' => 1, 'endColumn' => 10, 'startOffset' => 27, 'endOffset' => 36],
            $ast['children'][2]['pos'],
        );
    }

    public function testAnIllFormedByteInsideVerbatimContentSurvivesAsAReplacement(): void
    {
        // Verbatim content takes the same door: a code fence is not an escape
        // hatch out of the document's encoding, and before the fix the whole
        // block rendered empty here too.
        $this->assertSame(
            "<pre><code>a\u{FFFD}b\n</code></pre>\n",
            (new CarveConverter())->convert("```\na\x9bb\n```\n"),
        );
    }

    public function testTheCanonicalWriterRoundTripsTheReplacement(): void
    {
        // The canonical writer was the ONE target that kept the raw byte before
        // the fix, so it is the row that proves the door closed rather than a
        // fourth site being patched: its output is now well-formed too, and
        // re-reading it is a fixed point.
        $carve = (new CarveConverter())->toCarve("hello \x9b world\n");

        $this->assertSame("hello \u{FFFD} world\n", $carve);
        $this->assertSame($carve, (new CarveConverter())->toCarve($carve));
    }
}
