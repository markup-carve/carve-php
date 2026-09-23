<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\PositionIndex;
use MarkupCarve\Carve\Parser\SourceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A resolved position is put on the line its offset is on, and that reading
 * used to re-read the source from the line start to the position. Once per
 * node, on a document that is ONE LONG LINE, the prefix it walked was the whole
 * document so far - so the parse was quadratic in the line's length
 * (carve-php#2238).
 *
 * `SourceMapLookupScaleTest` guards the same class of bug and could not see
 * this one: every shape it measures is a LINE, so the segment count grows while
 * each prefix stays short. The shape that was quadratic here is the opposite -
 * one segment, and every node deep inside it.
 *
 * TWO THINGS ARE PINNED, because each is worthless without the other.
 *
 * The reading is unchanged: `resolve()` through the document's index must agree
 * with `resolve()` re-reading the source, at every offset of every shape below.
 * That is the property a later change to the index would break silently, and
 * the only evidence that a faster reading is the same reading.
 *
 * The cost is bounded: a hundred thousand positions on a two-megabyte line
 * resolve in a twentieth of a second. Re-reading the prefix makes the same loop
 * move a hundred gigabytes, so this separates by two orders of magnitude rather
 * than by a ratio near its bound - measured at 7.04s against 0.04s on the
 * commit before the fix, against a 2s bound that leaves a coverage-instrumented
 * runner fifty times the healthy cost.
 */
class ResolvingAPositionDoesNotReadItsLinePrefixTest extends TestCase
{
    /**
     * A map over a whole source, as the block layer builds one for a paragraph
     * it copied through.
     */
    private function mapOver(string $source, bool $indexed): SourceMap
    {
        $map = SourceMap::contiguous(0, strlen($source), 1, 1);

        return $indexed ? $map->withSource($source, new PositionIndex($source)) : $map->withSource($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sourceProvider(): array
    {
        return [
            // The shape of the BBCode repair parse: one line, no line feed to
            // shorten any prefix.
            'one long line' => [str_repeat('\\*{*x*} ', 40)],
            // Lines the block layer joined into one run, which is the case
            // `onItsOwnLine()` exists for.
            'joined lines' => ["first line\nsecond line\nthird line\n"],
            'consecutive line feeds' => ["a\n\n\nb\n"],
            'a leading line feed' => ["\nabc\n"],
            'a line feed only' => ["\n"],
            // A carriage return is not a line feed and must not be counted as
            // one, in either spelling.
            'crlf endings' => ["first\r\nsecond\r\nthird\r\n"],
            'lone carriage returns' => ["first\rsecond\rthird\r"],
            // Multi-byte text: the reading is in bytes on both sides, so a
            // continuation byte must not move the answer.
            'a no-break space and an em dash' => ["a\u{00A0}b\u{2014}c\nd\u{00A0}e\u{2014}f\n"],
            'punctuated prose' => ["I tried it, and it worked.\nIt's a well-known thing.\n"],
            'no line feed at all' => ['plain prose, with punctuation.'],
            'empty' => [''],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testTheIndexedReadingIsTheScannedReading(string $source): void
    {
        $indexed = $this->mapOver($source, indexed: true);
        $scanned = $this->mapOver($source, indexed: false);

        $length = strlen($source);
        for ($offset = 0; $offset <= $length; $offset++) {
            $this->assertSame(
                $scanned->resolve($offset),
                $indexed->resolve($offset),
                sprintf('offset %d of %d', $offset, $length),
            );
        }
    }

    public function testAHundredThousandPositionsOnOneLineStayCheap(): void
    {
        // Line feeds only at the very start, so almost every position below
        // resolves with the whole document behind it - and takes the branch
        // that reports a crossed line, which is the one that used to scan.
        $source = "a\nb\n" . str_repeat('x', 2000000);
        $map = $this->mapOver($source, indexed: true);
        $length = strlen($source);
        $step = intdiv($length, 100000);

        $started = hrtime(true);
        $last = null;
        for ($offset = 0; $offset < $length; $offset += $step) {
            $last = $map->resolve($offset);
        }
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNotNull($last);
        $this->assertLessThan(
            2.0,
            $elapsed,
            sprintf('Resolving 100000 positions took %.2fs; the prefix is being read again.', $elapsed),
        );
    }
}
