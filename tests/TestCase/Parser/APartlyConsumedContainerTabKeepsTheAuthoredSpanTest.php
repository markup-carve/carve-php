<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block nested in a list whose body line starts with a tab the container
 * strip consumed only part of (markup-carve/carve#2353).
 *
 * The strip gives back the columns a straddling tab still claims as SPACES, so
 * the line this stanza reads is one character longer than the line the author
 * wrote. Mapping it straight put every gap one byte early and left the authored
 * `x` on the newline, where the validity check dropped it.
 *
 * The starts asserted here are markup-carve/carve-js#2179's, which is the half
 * the spec fixture `a-partly-consumed-container-tab-keeps-the-authored-span`
 * pins. That fixture asserts starts only, and so does this file: the paragraph's
 * END is measured below without being asserted as intent, because no ruling
 * states it.
 */
class APartlyConsumedContainerTabKeepsTheAuthoredSpanTest extends TestCase
{
    /**
     * The ticket's document. The body line's first character is a literal tab
     * and the list's content column is 2, so the strip consumes two of the four
     * columns the tab owes and two remain.
     *
     * @var string
     */
    private const SOURCE = "- a\n\n  ::: |\n\t  x\n  :::\n";

    /**
     * The verse line's nodes, in order, each with its start or null when it
     * published no position.
     *
     * @return array<string, array{string, list<array{string, array<int>|null}>}>
     */
    public static function stanzas(): array
    {
        return [
            'the reproducer' => [
                self::SOURCE,
                [
                    ['paragraph', [4, 1, 13]],
                    ['non_breaking_space', null],
                    ['non_breaking_space', [4, 1, 13]],
                    ['non_breaking_space', [4, 2, 14]],
                    ['non_breaking_space', [4, 3, 15]],
                    ['text', [4, 4, 16]],
                ],
            ],
            'a leading tab with nothing consuming it' => [
                "::: |\n\tx\n:::\n",
                [
                    ['paragraph', [2, 1, 6]],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['text', [2, 2, 7]],
                ],
            ],
            'spaces where the reproducer has a tab' => [
                "- a\n\n  ::: |\n    x\n  :::\n",
                [
                    ['paragraph', [4, 3, 15]],
                    ['non_breaking_space', [4, 3, 15]],
                    ['non_breaking_space', [4, 4, 16]],
                    ['text', [4, 5, 17]],
                ],
            ],
            'no leading whitespace at all' => [
                "::: |\nx\n:::\n",
                [
                    ['paragraph', [2, 1, 6]],
                    ['text', [2, 1, 6]],
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<array{string, array<int>|null}> $expected
     */
    #[DataProvider('stanzas')]
    public function testTheVerseLinePublishesTheseStarts(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->verseStarts($source));
    }

    public function testTheAuthoredTextKeepsItsExactSpan(): void
    {
        $x = null;
        foreach ($this->placed(self::SOURCE) as [$type, $pos]) {
            if ($type === 'text' && $pos['startLine'] === 4) {
                $x = $pos;
            }
        }

        $this->assertNotNull($x, 'the authored text on the verse line published no position at all');
        $this->assertSame(16, $x['startOffset']);
        $this->assertSame(17, $x['endOffset']);
        $this->assertSame('x', substr(self::SOURCE, $x['startOffset'], $x['endOffset'] - $x['startOffset']));
    }

    public function testNoNodePublishesAnEmptySpan(): void
    {
        $empty = [];
        foreach ($this->placed(self::SOURCE) as [$type, $pos]) {
            if ($pos['startOffset'] === $pos['endOffset']) {
                $empty[] = $type;
            }
        }

        $this->assertSame([], $empty);
    }

    /**
     * Two columns of the tab survive the strip and two literal spaces follow it,
     * so four gap nodes stand on the line. Only three carry a position: `x` holds
     * 16..17 and three characters precede it, so the fourth placement does not
     * exist inside the line, and markup-carve/carve#2349 permits its omission.
     */
    public function testOnlyThreeOfTheFourGapsArePlaced(): void
    {
        $placed = 0;
        foreach ($this->placed(self::SOURCE) as [$type]) {
            if ($type === 'non_breaking_space') {
                $placed++;
            }
        }

        $this->assertSame(3, $placed);
    }

    /**
     * Columns are 1-based and counted in codepoints, so a published offset is
     * derivable from its own line and column. The paragraph and its first gap
     * used to claim line 4 with offset 12, the newline that ENDS line 3, and
     * column 0 where every other node is 1-based.
     *
     * @param string $source
     * @param list<array{string, array<int>|null}> $ignored
     */
    #[DataProvider('stanzas')]
    public function testEveryPublishedStartAddressesItsOwnOffset(string $source, array $ignored): void
    {
        $starts = [0];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === "\n") {
                $starts[] = $i + 1;
            }
        }

        $inconsistent = [];
        foreach ($this->placed($source) as [$type, $pos]) {
            $lineStart = $starts[$pos['startLine'] - 1] ?? null;
            $this->assertNotNull($lineStart, $type . ' claims a line the source does not have');
            $lineEnd = $starts[$pos['startLine']] ?? strlen($source);
            $line = substr($source, $lineStart, $lineEnd - $lineStart);
            $derived = $lineStart + strlen((string)mb_substr($line, 0, $pos['startColumn'] - 1));
            if ($pos['startColumn'] < 1 || $derived !== $pos['startOffset']) {
                $inconsistent[] = $type;
            }
        }

        $this->assertSame([], $inconsistent);
    }

    /**
     * Every node that published a position, in document order.
     *
     * @return list<array{0: string, 1: array<string, int>}>
     */
    private function placed(string $source): array
    {
        $found = [];
        foreach ($this->walk($this->publish($source)) as [$type, $pos]) {
            if ($pos !== null) {
                $found[] = [$type, $pos];
            }
        }

        return $found;
    }

    /**
     * The stanza's own nodes, so an unplaced gap is visible as a row rather than
     * as an absence.
     *
     * @return list<array{string, array<int>|null}>
     */
    private function verseStarts(string $source): array
    {
        $starts = [];
        foreach ($this->walk($this->publish($source), false) as [$type, $pos, $inVerse]) {
            if (!$inVerse || !in_array($type, ['paragraph', 'non_breaking_space', 'text'], true)) {
                continue;
            }
            $starts[] = [
                $type,
                $pos === null ? null : [$pos['startLine'], $pos['startColumn'], $pos['startOffset']],
            ];
        }

        return $starts;
    }

    /**
     * @return array<string, mixed>
     */
    private function publish(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param bool $inVerse
     *
     * @return list<array{0: string, 1: array<string, int>|null, 2: bool}>
     */
    private function walk(array $node, bool $inVerse = false): array
    {
        $out = [];
        if (isset($node['type']) && is_string($node['type'])) {
            /** @var array<string, int>|null $pos */
            $pos = is_array($node['pos'] ?? null) ? $node['pos'] : null;
            $out[] = [$node['type'], $pos, $inVerse];
            $inVerse = $inVerse || $node['type'] === 'line_block';
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    array_push($out, ...$this->walk($child, $inVerse));
                }
            }
        }

        return $out;
    }
}
