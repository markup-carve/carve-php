<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block's spaced content is placeable, so it is placed.
 *
 * PART 12 §4 exempts a node the producer REASSEMBLED, because no honest span
 * exists for it. A line block's preserved whitespace is not that: PART 2 §23
 * turns a leading run, and any inner run of two columns or more, into that many
 * non-breaking spaces, and where the run is plain SPACES each one stands for
 * exactly one source column. A true span exists, carve-rs publishes it, and
 * carve-php dropped it (carve-php#1351) because the map could not describe a
 * region where one source byte became a three-byte sentinel.
 *
 * A TAB is the real exemption and the control below keeps it one. It widens to
 * between one and four columns depending on where it starts, so its sentinels
 * stand for no fixed count of source bytes; all three engines decline there, and
 * the spec repo declares that form `permitted` for all three. The asymmetry is
 * the whole discriminator - when the cause is the construct, every engine omits.
 *
 * ASSERTED AS THE SLICE THE SPAN SELECTS, never as rendered output. This defect
 * changed no HTML at all, which is exactly why three corpus documents carried it
 * without a single fixture noticing: a render assertion cannot fail on a
 * position.
 */
class LineBlockSpacedContentIsPlacedTest extends TestCase
{
    /**
     * Every text node of a parse, as `[value, selected source or null]`.
     *
     * The selection is by CODEPOINT (PART 12 §4's unit), which is why it is
     * taken with `mb_substr` - byte slicing agrees on ASCII and would let a
     * wrong unit through unseen.
     *
     * @return list<array{string, string|null}>
     */
    private function texts(string $source): array
    {
        $found = [];
        foreach ($this->nodesOfType($source, 'text') as $node) {
            $value = $node['value'];
            $this->assertIsString($value);
            $found[] = [$value, $this->selection($node, $source)];
        }

        return $found;
    }

    /**
     * Every node of `$type` in a parse of `$source`, in document order.
     *
     * @return list<array<string, mixed>>
     */
    private function nodesOfType(string $source, string $type): array
    {
        $encoded = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
        $found = [];
        $walk = function (array $node) use (&$walk, &$found, $type): void {
            if (($node['type'] ?? null) === $type) {
                $found[] = $node;
            }
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if (isset($value['type'])) {
                    $walk($value);

                    continue;
                }
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $walk($item);
                    }
                }
            }
        };
        $walk($encoded);

        return $found;
    }

    /**
     * The source a node's span selects, or null where it has none.
     *
     * @param array<string, mixed> $node
     * @param string $source
     */
    private function selection(array $node, string $source): ?string
    {
        $pos = $node['pos'] ?? null;
        if ($pos === null) {
            return null;
        }

        $this->assertIsArray($pos);
        $start = $pos['startOffset'];
        $end = $pos['endOffset'];
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        return mb_substr($source, $start, $end - $start, 'UTF-8');
    }

    /**
     * @return array<string, array{0: string, 1: list<string|null>}>
     */
    public static function placedProvider(): array
    {
        return [
            // The reported document, `41-line-blocks-2`. carve-rs places the
            // second text node at 21-40, which selects the indentation with it.
            'a leading run of two columns' => [
                "::: |\nRoses are red,\n  Violets are blue.\n:::\n",
                ['Roses are red,', '  Violets are blue.'],
            ],
            // `41-line-blocks-3`: interior runs, so the sentinels sit between
            // two mapped runs rather than before one.
            'interior runs' => [
                "::: |\nTwo roads    diverged in a yellow wood,\nAnd looked   down one as far as I could\n:::\n",
                [
                    'Two roads    diverged in a yellow wood,',
                    'And looked   down one as far as I could',
                ],
            ],
            // `268-trailing-whitespace-on-a-content-line-is-dropped-12`. Two
            // trailing columns are CONTENT (§23), so the span covers them -
            // while the ONE trailing column of `def ` is dropped and does not.
            'a trailing run of two columns' => [
                "::: |\nabc  \ndef \n:::\n",
                ['abc  ', 'def'],
            ],
            // A leading run of ONE column is still sentinels, because nothing
            // has been seen on the line yet. A rule written as "two or more"
            // would leave this one behind.
            'a leading run of one column' => [
                "::: |\n a\n:::\n",
                [' a'],
            ],
            // The offsets are codepoints, and only a non-BMP character can tell
            // that from bytes or from UTF-16 units.
            'an astral character before the run' => [
                "::: |\n\u{1F600}  x\n:::\n",
                ["\u{1F600}  x"],
            ],
            // A container has already stripped its prefix from the line the
            // stanza was handed, so the columns are measured against that and
            // not against the physical line.
            'inside a blockquote' => [
                "> ::: |\n>   a b\n> :::\n",
                ['  a b'],
            ],
            'inside a list item' => [
                "- ::: |\n    a  b\n  :::\n",
                ['  a  b'],
            ],
            // THE TWO REWRITES COMPOSE. The block layer turns the leading run
            // into sentinels; the inline layer turns `\ ` into one more. Each
            // form alone was placed and the pair declined, because the escape
            // check ran against raw source that still held the block layer's
            // spaces.
            'a preserved run beside an escaped space' => [
                "::: |\n  a\\ b\n:::\n",
                ['  a\\ b'],
            ],
            // A TAB LINE MUST NOT COST THE STANZA'S OTHER LINES their
            // positions. The tab run is skipped, so the map has a hole there
            // and only the nodes over the hole decline. Recorded instead, it
            // would claim three source columns where the source has one
            // character, overlap the run after it, and leave the whole segment
            // list unsearchable - taking `a  b` down with it.
            'a spaced line beside a tab line' => [
                "::: |\na  b\nc\td\n:::\n",
                ['a  b', null],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<string|null> $selected
     */
    #[DataProvider('placedProvider')]
    public function testTheSpanSelectsTheSourceTheNodeWasBuiltFrom(string $source, array $selected): void
    {
        $this->assertSame($selected, array_column($this->texts($source), 1));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function declinedProvider(): array
    {
        return [
            // `41-line-blocks-9`, the control. Every engine omits here.
            'a tab-widened run' => ["::: |\ntab\tgap\nwide\t\tgap\n\tlead\n:::\n"],
            // A tab does not have to be alone to spoil the correspondence. One
            // inside a run of spaces makes the whole run unmappable, and a rule
            // that only looked at the first character would place this wrongly.
            'a tab inside a run of spaces' => ["::: |\na \t b\n:::\n"],
            // Composing the rewrites must not let a tab through the back door:
            // the escape check runs on what the map replayed, and the map
            // replays nothing for a run it refused to record.
            'a tab run beside an escaped space' => ["::: |\n\ta\\ b\n:::\n"],
        ];
    }

    public function testATabRunIsSkippedRatherThanApproximated(): void
    {
        // THE OTHER HALF OF THE TAB RULE, and the one a text-node sweep cannot
        // see. A text node over a mismapped run declines, because its span is
        // verified against the source; a node placed from the extent the parser
        // measured - emphasis, a link, a code span - is not, so a segment that
        // claims more source columns than the run has moves it silently.
        //
        // Recording `\t` as one sentinel per column would give this `strong` the
        // span 10-11, selecting the closing `*` alone.
        $source = "::: |\nc\t*d* e\n:::\n";
        $strong = $this->nodesOfType($source, 'strong');

        $this->assertCount(1, $strong);
        $this->assertSame('*d*', $this->selection($strong[0], $source));
    }

    #[DataProvider('declinedProvider')]
    public function testATabWidenedRunStillPublishesNoPosition(string $source): void
    {
        $texts = $this->texts($source);

        $this->assertNotSame([], $texts);
        foreach ($texts as [$value, $selection]) {
            $this->assertNull($selection, 'expected no position for ' . json_encode($value));
        }
    }
}
