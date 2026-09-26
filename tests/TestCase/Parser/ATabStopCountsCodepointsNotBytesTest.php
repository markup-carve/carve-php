<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\NonBreakingSpace;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tab reaches its stop by COLUMN, and a column counts Unicode codepoints.
 *
 * PART 9 §24 C1 runs the column walk a tab advances through, and PART 12 §4
 * fixes the unit: a column is a count of codepoints, never bytes and never
 * UTF-16 code units, because a byte offset can land inside a UTF-8 sequence and
 * a UTF-16 offset inside a surrogate pair. This engine advanced the column one
 * per BYTE, so the stop a tab reached depended on the UTF-8 encoding of the text
 * before it (markup-carve/carve#2354).
 *
 * The line block is where the reading is observable: §23 turns an inner
 * whitespace run of two or more columns into that many NBSP, so the column the
 * tab owes is readable off the output. Outside a line block the same run is
 * folded to one space, and every unit agrees on a folded space.
 *
 * WHAT THE ROWS DISCRIMINATE. Each non-ASCII row is paired with the ASCII run of
 * the SAME codepoint length: `é` must behave as `a`, `e` plus U+0301 as `ab`.
 * U+1F600 separates codepoints from UTF-16 as well as from bytes - one
 * codepoint, two UTF-16 units, four bytes, so all three units predict a
 * different answer. The three-codepoint non-ASCII row moves the other way: nine
 * bytes owed three columns where three codepoints owe one, so it fails on a
 * byte reading that the shorter rows would let through. `e` plus U+0301 is the
 * fourth unit's control: two codepoints but one display cell, so it also states
 * that the ruling chose codepoints over cells.
 *
 * THE ZERO ROWS ARE NOT AN OMISSION BUG. A run owing exactly one column is
 * written as an ordinary space and the text around it merges, which
 * markup-carve/carve#2349 declared permitted. `abc` reaches it in pure ASCII, so
 * the boundary has nothing to do with encoding - it rides along here only
 * because the codepoint count decides WHICH rows land on it.
 */
class ATabStopCountsCodepointsNotBytesTest extends TestCase
{
    /**
     * Tab stops sit at multiples of four (PART 2).
     *
     * @var int
     */
    protected const TAB_STOP = 4;

    /**
     * The text before the tab, and the codepoints it spans.
     *
     * The expected NBSP count is DERIVED from the codepoint count rather than
     * listed, so a row cannot be written to agree with whatever the parser does.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function beforeProvider(): array
    {
        return [
            'one ASCII letter' => ['a', 1],
            'two ASCII letters' => ['ab', 2],
            'three ASCII letters' => ['abc', 3],
            'four ASCII letters' => ['abcd', 4],
            'U+00E9 (2 bytes, 1 codepoint)' => ["\u{00E9}", 1],
            'U+20AC (3 bytes, 1 codepoint)' => ["\u{20AC}", 1],
            'U+1F600 (4 bytes, 2 UTF-16 units, 1 codepoint)' => ["\u{1F600}", 1],
            'e then U+0301 (3 bytes, 2 codepoints)' => ["e\u{0301}", 2],
            'three non-ASCII codepoints (9 bytes)' => ["\u{00E9}\u{20AC}\u{1F600}", 3],
            'four non-ASCII codepoints (10 bytes)' => ["\u{00E9}\u{20AC}\u{1F600}x", 4],
        ];
    }

    /**
     * The tab owes the columns its stop is away from the codepoint count, and a
     * run of two or more columns is that many NBSP.
     */
    #[DataProvider('beforeProvider')]
    public function testTheTabOwesColumnsCountedInCodepoints(string $before, int $codepoints): void
    {
        $owed = self::TAB_STOP - ($codepoints % self::TAB_STOP);
        $expected = $owed >= 2 ? $owed : 0;

        $this->assertSame($codepoints, mb_strlen($before, 'UTF-8'), 'the row states its own codepoint count');
        $this->assertSame($expected, $this->nbspCount($before), $this->html($before));
    }

    /**
     * The same reading stated without arithmetic: a run of N codepoints must
     * reach the same stop as N ASCII letters, so the two render alike once the
     * text itself is set aside.
     */
    #[DataProvider('beforeProvider')]
    public function testANonAsciiRunBehavesAsTheAsciiRunOfTheSameLength(string $before, int $codepoints): void
    {
        $ascii = str_repeat('a', $codepoints);

        $this->assertSame(
            $this->nbspCount($ascii),
            $this->nbspCount($before),
            $this->html($before) . ' vs ' . $this->html($ascii),
        );
    }

    /**
     * The ASCII control for the permitted omission: three columns before a tab
     * owe exactly one, so the gap is an ordinary space and the text merges.
     * Nothing about this row depends on encoding, which is why it pins the
     * boundary the zero rows above land on.
     */
    public function testAnAsciiRunOwingOneColumnMergesItsTextInstead(): void
    {
        $this->assertStringContainsString('<p>abc x</p>', $this->html('abc'));
        $this->assertSame(0, $this->nbspCount('abc'));
    }

    /**
     * A byte reading would have written the emoji row as four NBSP and a UTF-16
     * reading as two. Naming both keeps the row from passing under either.
     */
    public function testTheEmojiRowRejectsBytesAndUtf16Alike(): void
    {
        $emoji = "\u{1F600}";

        $this->assertSame(4, strlen($emoji));
        $this->assertSame(1, mb_strlen($emoji, 'UTF-8'));
        $this->assertSame(3, $this->nbspCount($emoji), $this->html($emoji));
    }

    /**
     * The other direction: nine bytes owe three columns, three codepoints owe
     * one. A byte reading writes three NBSP where the rule writes none.
     */
    public function testThreeNonAsciiCodepointsOweOneColumnNotThree(): void
    {
        $before = "\u{00E9}\u{20AC}\u{1F600}";

        $this->assertSame(9, strlen($before));
        $this->assertSame(0, $this->nbspCount($before), $this->html($before));
    }

    /**
     * The column unit moved; the SPANS did not. Offsets and columns were already
     * codepoints (PART 12 §4) and the two readings must not be conflated, so the
     * block geometry over a line whose first character spans three bytes is the
     * same number before and after. This half holds on both sides of the fix and
     * is here as the control for the half below.
     */
    public function testTheBlockGeometryOverAMultiByteLineDoesNotMove(): void
    {
        $encoded = $this->tracked("::: |\n\u{20AC}\tx\n:::\n");
        $paragraph = $encoded['children'][0]['children'][0];

        // Line 2 opens at codepoint 6. U+20AC is one codepoint, the tab one
        // more, `x` one more: the paragraph ends at 9, not at the byte 11.
        $this->assertSame(6, $paragraph['pos']['startOffset']);
        $this->assertSame(9, $paragraph['pos']['endOffset']);
        $this->assertSame(0, $encoded['children'][0]['pos']['startOffset']);
        $this->assertSame(13, $encoded['children'][0]['pos']['endOffset']);
    }

    /**
     * And the inline spans the corrected column newly makes placeable are
     * codepoints too. The byte reading owed this run one column, which merged
     * the text into a single unplaced node, so there was no `x` node here to
     * carry a span at all - the fix does not move an offset, it publishes one
     * §4 forbade inventing.
     */
    public function testTheTextAfterTheTabCarriesCodepointOffsets(): void
    {
        $encoded = $this->tracked("::: |\n\u{20AC}\tx\n:::\n");
        $children = $encoded['children'][0]['children'][0]['children'];
        $last = $children[array_key_last($children)];

        $this->assertSame('text', $last['type']);
        $this->assertSame('x', $last['value']);
        $this->assertSame(8, $last['pos']['startOffset']);
        $this->assertSame(9, $last['pos']['endOffset']);
        $this->assertSame(3, $last['pos']['startColumn']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function tracked(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
    }

    protected function html(string $before): string
    {
        return (new CarveConverter())->convert($this->source($before));
    }

    protected function source(string $before): string
    {
        return "::: |\n" . $before . "\tx\n:::\n";
    }

    protected function nbspCount(string $before): int
    {
        return $this->countNonBreakingSpaces((new BlockParser())->parse($this->source($before)));
    }

    protected function countNonBreakingSpaces(Node $node): int
    {
        $count = $node instanceof NonBreakingSpace ? 1 : 0;
        foreach ($node->getChildren() as $child) {
            $count += $this->countNonBreakingSpaces($child);
        }

        return $count;
    }
}
