<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * The text runs of the paragraph a capped container degrades to are placed.
 *
 * carve-php#946 placed that paragraph and its soft breaks; its four `text` runs
 * were still published with no `pos`, which is the whole of this engine's
 * outstanding column in the spec's `resources/ast-position-waivers.txt`.
 *
 * THIS IS NOT THE PART 12 §4 EXEMPTION. §4 permits omitting `pos` on a
 * REASSEMBLED node and names them - a synthesized hard break, line-block content
 * rebuilt around a sentinel, a cell put back together from continuation lines, a
 * text run whose pieces are not adjacent. A degraded run is none of those: it is
 * a contiguous slice of exactly one source line. carve-js publishes all four and
 * its spans pass the slice rule, which is the demonstration that an honest span
 * EXISTS rather than merely not having been written down (`docs/ast-json.md`
 * lines 116-117 and 438).
 *
 * A node whose PARENT is placed and whose own span is missing is also the
 * awkward case for a consumer: it can resolve an offset to the paragraph and
 * then not descend into it.
 *
 * markup-carve/carve#913 ruled `pos` markup-inclusive with a CONTAINMENT
 * INVARIANT - a parent's span must contain every child's - so both halves are
 * asserted here: each run slices to itself, and each run lies inside its
 * paragraph.
 *
 * WHAT IS DELIBERATELY LEFT UNPLACED. The lines reaching the degraded paragraph
 * have had a container prefix stripped, so nothing about a run's offset can be
 * assumed; the placement runs only where the source proves the mapping, and the
 * refusal cases below are as much the specification as the placements. §4 rates
 * a wrong span worse than an absent one, so the group is all or nothing.
 */
class OverCapParagraphPlacesItsTextRunsTest extends TestCase
{
    /**
     * 203 openers, matching corpus
     * `182-openers-past-the-nesting-cap-are-one-paragraph` - the document
     * `npm run ast:check` reports.
     */
    private function overCapDocument(): string
    {
        return str_repeat(":::: note\n", 203) . "x\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function publish(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    /**
     * The paragraphs past the cap, each with its own children.
     *
     * @return array<int, array<string, mixed>>
     */
    private function degradedParagraphs(string $source): array
    {
        return $this->collect($this->publish($source), ['paragraph']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string> $types
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $node, array $types): array
    {
        $found = [];
        if (in_array($node['type'] ?? '', $types, true)) {
            $found[] = $node;
        }
        foreach (['children', 'items', 'rows'] as $key) {
            /** @var array<mixed> $branch */
            $branch = $node[$key] ?? [];
            foreach ($branch as $child) {
                if (is_array($child) && isset($child['type'])) {
                    $found = array_merge($found, $this->collect($child, $types));
                }
            }
        }

        return $found;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $node
     */
    private function slice(string $source, array $node): ?string
    {
        $pos = $node['pos'] ?? null;
        if ($pos === null) {
            return null;
        }

        return substr($source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']);
    }

    public function testEveryTextRunOfTheDegradedParagraphIsPlaced(): void
    {
        $source = $this->overCapDocument();
        $runs = $this->collect($this->publish($source), ['text']);

        $this->assertCount(4, $runs, 'corpus 182 degrades to three openers and a body line');
        foreach ($runs as $offset => $run) {
            $this->assertArrayHasKey('pos', $run, "text run {$offset} has no position");
        }
    }

    /**
     * Present is not enough.
     *
     * The failure shape catalogued in markup-carve/carve#755 is a span that
     * slices to plausible text at the wrong place - carve-php once gave a `text`
     * node `[0,1]` where the others gave `[4,5]` and BOTH sliced to `"*"`. So
     * each run is asserted against the source it claims to cover AND against the
     * distinct text it was built from.
     */
    public function testEachRunSlicesToExactlyItsOwnSource(): void
    {
        $source = $this->overCapDocument();
        $runs = $this->collect($this->publish($source), ['text']);

        $expected = [':::: note', ':::: note', ':::: note', 'x'];
        $starts = [];
        foreach ($runs as $offset => $run) {
            $this->assertSame($expected[$offset], $run['value']);
            $this->assertSame($expected[$offset], $this->slice($source, $run), "text run {$offset}");
            $starts[] = $run['pos']['startOffset'];
        }

        // Three runs carry the SAME text, so equal slices prove nothing on
        // their own - a placement that returned the first opener's offset for
        // all three would pass every assertion above. The offsets have to be
        // distinct and ascending.
        $this->assertSame($starts, array_unique($starts));
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    /**
     * markup-carve/carve#913's containment invariant, asserted as its own rule.
     */
    public function testEachRunLiesInsideItsParagraph(): void
    {
        $source = $this->overCapDocument();
        $paragraphs = $this->degradedParagraphs($source);

        $this->assertCount(1, $paragraphs);
        $paragraph = $paragraphs[0];
        $this->assertArrayHasKey('pos', $paragraph);

        foreach ($this->collect($paragraph, ['text']) as $offset => $run) {
            $this->assertGreaterThanOrEqual(
                $paragraph['pos']['startOffset'],
                $run['pos']['startOffset'],
                "text run {$offset} starts before its paragraph",
            );
            $this->assertLessThanOrEqual(
                $paragraph['pos']['endOffset'],
                $run['pos']['endOffset'],
                "text run {$offset} ends after its paragraph",
            );
        }
    }

    /**
     * The prefix case, which is why the run is matched as a SUFFIX of its line.
     *
     * Inside a block quote the lines that reach the degraded paragraph have had
     * `> ` stripped, so a placement that assumed a run starts at its line's
     * start would be off by the prefix width at every run - and would still
     * slice to plausible text, because the source there is the same repeated
     * opener. Asserting the slice is what catches it.
     */
    public function testARunInsideAContainerIsPlacedPastTheStrippedPrefix(): void
    {
        $body = rtrim(str_repeat(":::: note\n", 203) . 'x');
        $source = '> ' . str_replace("\n", "\n> ", $body) . "\n";

        $runs = $this->collect($this->publish($source), ['text']);

        // The quote spends a nesting level of its own, so the cap falls one
        // opener earlier than it does at the top level - which is why the count
        // is read from the tree rather than written down.
        $this->assertGreaterThan(1, count($runs));
        foreach ($runs as $offset => $run) {
            $this->assertArrayHasKey('pos', $run, "text run {$offset} has no position");
            $this->assertSame($run['value'], $this->slice($source, $run), "text run {$offset}");
            // The `> ` before it must NOT be inside the run's span.
            $this->assertSame('> ', substr($source, $run['pos']['startOffset'] - 2, 2));
        }
        $this->assertSame('x', $runs[count($runs) - 1]['value']);
    }

    /**
     * Two groups, because the placement is per group and takes its first line
     * from the group rather than from the paragraph before it.
     */
    public function testASecondGroupAfterABlankLineIsPlacedToo(): void
    {
        $source = str_repeat(":::: note\n", 203) . "a\n\nb\n";
        $paragraphs = $this->degradedParagraphs($source);

        $this->assertCount(2, $paragraphs);
        foreach ($paragraphs as $paragraph) {
            foreach ($this->collect($paragraph, ['text']) as $run) {
                $this->assertArrayHasKey('pos', $run);
                $this->assertSame($run['value'], $this->slice($source, $run));
            }
        }
    }

    /**
     * A paragraph whose inline shape does not match its lines places NOTHING.
     *
     * Two shapes, both real: smart typography splits one line into more than one
     * run, and a trailing backslash turns a soft break into a hard one. In
     * either case the positional match between children and lines is broken, so
     * every run in that paragraph declines a position rather than taking a
     * guessed one - and it is all or nothing, because a half-placed paragraph is
     * a new shape for a consumer to handle.
     *
     * These are the rows that keep the placement from being written as "walk the
     * lines and hand out offsets". They are also the rows that reach the two
     * conditions the run-count check would otherwise mask: the backslash case
     * leaves the run count intact and is rejected by the SUFFIX check alone,
     * and it is what makes the all-or-nothing rule observable - without it the
     * paragraph's other three runs would be placed and only the fourth left
     * bare.
     */
    public function testAParagraphWhoseShapeDoesNotMatchItsLinesPlacesNothing(): void
    {
        $sources = [
            'smart typography splits a run' => str_repeat(":::: note\n", 202) . ":::: \"q\"\nx\n",
            'a trailing backslash makes a hard break' => str_repeat(":::: note\n", 202) . ":::: note\\\nx\n",
        ];

        foreach ($sources as $label => $source) {
            foreach ($this->collect($this->publish($source), ['text']) as $offset => $run) {
                $this->assertArrayNotHasKey('pos', $run, "{$label}: run {$offset} was placed anyway");
            }
        }
    }

    /**
     * The control.
     *
     * A document INSIDE the cap never reaches this code, so it must be
     * unaffected. A change that broke ordinary inline placement fails here and
     * nowhere else in this file.
     */
    public function testADocumentInsideTheCapStillPlacesItsTextRuns(): void
    {
        $source = ":::: note\nbody\nmore\n::::\n";

        foreach ($this->collect($this->publish($source), ['text']) as $run) {
            $this->assertArrayHasKey('pos', $run);
            $this->assertSame($run['value'], $this->slice($source, $run));
        }
    }
}
