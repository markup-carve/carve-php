<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 INPUT + PART 12 §4: THE DOCUMENT §4 MEASURES IS THE ONE THAT HAS THE
 * REPLACEMENT (markup-carve/carve#1525, markup-carve/carve-php#1563).
 *
 * PART 0 replaces U+0000 with U+FFFD before the first line is read, so the
 * document every later rule describes is the substituted one. §4 counts UNICODE
 * CODEPOINTS, and U+FFFD is one codepoint and three UTF-8 bytes - so a
 * substitution is the one edit that makes the arriving bytes and the parsed
 * document disagree about a character's WIDTH as well as its identity.
 *
 * WHAT WENT WRONG. The substitution ran AFTER `BlockParser::parse()` captured
 * the string the offset table is built from, and one stale string produced two
 * separate wrong answers:
 *
 * - `PositionIndex` converts the parser's byte offsets to codepoints using the
 *   string it is handed. Handed the PRE-substitution text it saw a NUL - one
 *   ASCII byte - took its pure-ASCII identity path, and published the parser's
 *   POST-substitution byte offset with no conversion at all. `a` U+FFFD `b`
 *   ended at 5, its byte length, where carve-js and carve-rs end it at 3.
 * - `SourceMap::spanFor()` verifies a node by slicing the same string and
 *   comparing it to the node's own text. The slice held the NUL where the text
 *   held U+FFFD, so the check failed honestly and the text node was published
 *   with NO position.
 *
 * SO THE BLAST RADIUS IS NOT THE NUL'S OWN NODE. Every offset after the
 * substitution moved, including blocks holding no NUL - `testEveryLaterBlockIs
 * MeasuredInCodepointsToo` is the case that says so, and it is the reason this
 * is filed against the offset table rather than against the fixture.
 *
 * PRESENCE IS ASSERTED BEFORE EXTENT throughout: `trackPositions` is opt-in, so
 * a comparison against a missing `pos` key passes against the unfixed parser
 * exactly as happily as against the fixed one (carve#755, carve-php#978).
 */
class AReplacedNulLeavesCodepointOffsetsTest extends TestCase
{
    /**
     * The corpus fixtures, plus the controls that say what the defect was NOT.
     *
     * Expected offsets are carve-js's and carve-rs's, which agree byte for byte
     * with each other and with §4; they were re-measured against both engines
     * rather than copied from the ledger.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: int}>
     */
    public static function documents(): array
    {
        return [
            // Corpus `397-a-null-byte-is-replaced-before-the-document-is-read`.
            // Three codepoints, five UTF-8 bytes: the whole defect in four
            // bytes of source.
            'the corpus NUL fixture' => ["a\x00b\n", 'children.0', 0, 3, 4],
            // Corpus `397-...-2`, the same shape inside a code span - a second
            // node kind reached by the same table.
            'a NUL in a code span' => ["`a\x00b`\n", 'children.0', 0, 5, 6],
            // CONTROL, corpus `397-...-3`: a vertical tab is a control byte
            // that is NOT substituted, so source and document still agree and
            // this row was unanimous across all three engines even unfixed.
            'the vertical-tab control' => ["c\x0Bd\n", 'children.0', 0, 3, 4],
            // CONTROL: ordinary multibyte input. `é` is one codepoint and two
            // bytes, and this was unanimous unfixed too - which is what rules
            // out "carve-php counts bytes everywhere" and points at the
            // substitution specifically.
            'a multibyte control' => ["a\u{00E9}b\n", 'children.0', 0, 3, 4],
            // CONTROL: the replacement character written by the AUTHOR. Same
            // three codepoints and five bytes as the fixture, and correct
            // unfixed - because no substitution happened, so the string the
            // table was built from was the string the parser read.
            'an authored U+FFFD control' => ["a\u{FFFD}b\n", 'children.0', 0, 3, 4],
        ];
    }

    /**
     * The block's own extent, in codepoints.
     */
    #[DataProvider('documents')]
    public function testABlockIsMeasuredInCodepoints(
        string $source,
        string $path,
        int $startOffset,
        int $endOffset,
        int $endColumn,
    ): void {
        $node = $this->at($this->parseWithPositions($source), $path);

        $this->assertArrayHasKey('pos', $node, "no pos on $path");
        $this->assertSame($startOffset, $node['pos']['startOffset'], "start offset of $path");
        $this->assertSame($endOffset, $node['pos']['endOffset'], "end offset of $path");
        $this->assertSame(1, $node['pos']['startColumn'], "start column of $path");
        $this->assertSame($endColumn, $node['pos']['endColumn'], "end column of $path");
    }

    /**
     * AND THE OFFSETS SELECT THE RIGHT TEXT.
     *
     * The numbers alone cannot say which unit they are in - 3 is both the
     * codepoint count and a plausible byte count for a different document. The
     * slice can: it is taken from the SUBSTITUTED source by codepoint, which is
     * what a consumer of §4 does, and it has to come back as the construct.
     */
    #[DataProvider('documents')]
    public function testTheOffsetsSelectTheConstruct(
        string $source,
        string $path,
        int $startOffset,
        int $endOffset,
        int $endColumn,
    ): void {
        $document = str_replace("\0", "\u{FFFD}", $source);
        $node = $this->at($this->parseWithPositions($source), $path);
        $codepoints = preg_split('//u', $document, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $this->assertSame(
            rtrim($document, "\n"),
            implode('', array_slice($codepoints, $startOffset, $endOffset - $startOffset)),
            "the source $path selects",
        );
        $this->assertSame($startOffset, $node['pos']['startOffset'] ?? null);
        $this->assertSame($endOffset, $node['pos']['endOffset'] ?? null);
    }

    /**
     * THE TEXT NODE KEEPS ITS POSITION.
     *
     * The second symptom, and the one that says the two are the same defect:
     * `SourceMap::spanFor()` refuses a span whose slice does not reproduce the
     * node's text, and against the pre-substitution string it never could. The
     * refusal was correct; the string it was checking against was not.
     */
    public function testTheTextNodeKeepsItsPosition(): void
    {
        $text = $this->at($this->parseWithPositions("a\x00b\n"), 'children.0.children.0');

        $this->assertSame('text', $text['type'] ?? null);
        $this->assertSame("a\u{FFFD}b", $text['value'] ?? null);
        $this->assertArrayHasKey('pos', $text, 'the text node carries no position');
        $this->assertSame(0, $text['pos']['startOffset']);
        $this->assertSame(3, $text['pos']['endOffset']);
        $this->assertSame(4, $text['pos']['endColumn']);
    }

    /**
     * A NUL MOVES EVERY LATER OFFSET, so the fixture is only where the defect
     * was first seen and not the extent of it.
     *
     * The second paragraph holds no NUL at all and was still published two
     * codepoints too far right, its text node still position-less - because
     * both faults are properties of the document-wide table, not of the node
     * that happened to contain the substituted byte.
     */
    public function testEveryLaterBlockIsMeasuredInCodepointsToo(): void
    {
        $tree = $this->parseWithPositions("a\x00b\n\nsecond\n");
        $second = $this->at($tree, 'children.1');

        $this->assertArrayHasKey('pos', $second, 'the second paragraph carries no position');
        // `a` U+FFFD `b` (3) + blank line (2) = 5, against 7 in bytes.
        $this->assertSame(5, $second['pos']['startOffset'], 'a later block starts in codepoints');
        $this->assertSame(11, $second['pos']['endOffset'], 'a later block ends in codepoints');

        $text = $this->at($tree, 'children.1.children.0');
        $this->assertArrayHasKey('pos', $text, 'a later text node carries no position');
        $this->assertSame(5, $text['pos']['startOffset']);
        $this->assertSame(11, $text['pos']['endOffset']);
    }

    /**
     * `srcByteLength` IS DELIBERATELY LEFT WHERE IT WAS.
     *
     * It reports the bytes that ARRIVED, and carve-js and carve-rs report the
     * bytes of the substituted document instead - a real divergence, but a
     * separate question about what the field names rather than a unit error in
     * the offset table, and carve-php#1563 parks it for a decision. Pinned here
     * so moving the substitution earlier cannot change it as a side effect: the
     * substitution sits after the length is taken, and this is the assertion
     * that says so.
     */
    public function testTheSourceByteLengthStillCountsTheBytesThatArrived(): void
    {
        $this->assertSame(4, $this->parseWithPositions("a\x00b\n")['srcByteLength'] ?? null);
        $this->assertSame(4, $this->parseWithPositions("c\x0Bd\n")['srcByteLength'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWithPositions(string $source): array
    {
        $parser = new BlockParser(false, false, false, true);

        return (new AstCodec())->encode($parser->parse($source));
    }

    /**
     * @param array<string, mixed> $tree
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private function at(array $tree, string $path): array
    {
        $node = $tree;
        foreach (explode('.', $path) as $step) {
            $this->assertIsArray($node[$step] ?? null, "no node at $step in $path");
            $node = $node[$step];
        }

        return $node;
    }
}
