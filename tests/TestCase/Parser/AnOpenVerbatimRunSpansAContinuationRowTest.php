<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A verbatim run left open by a table row reaches across the continuation row.
 *
 * PART 9 §19 ends a verbatim run at its closing delimiter, and a row boundary
 * is not one. So a `|` inside a run the row above left open is CONTENT, not a
 * cell delimiter - the cell splitter has to start the continuation row in the
 * state the base row ended in.
 *
 * The corpus pins ONE document of this (333-4). Everything else about carrying
 * the state - which cell it belongs to, how wide it is, and the fast path that
 * skips the scan entirely - could be removed with the whole suite still green.
 * Those are the rows below; each was a surviving mutant, and carve-rs renders
 * every one of them as asserted.
 */
class AnOpenVerbatimRunSpansAContinuationRowTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * The corpus shape, restated: the pipe inside the open run is content.
     */
    public function testAPipeInsideTheOpenRunIsNotACellDelimiter(): void
    {
        $html = $this->html("| a `b |\n+ c | d` |\n");

        $this->assertStringContainsString('<td>a <code>b c | d</code></td>', $html, $html);
        $this->assertStringNotContainsString('<code></code>', $html, $html);
    }

    /**
     * THE RUN BELONGS TO THE CELL IT WAS WRITTEN IN. Carried from the row's
     * start instead, cell 0 of the continuation would reopen too and `x y`
     * would never split off.
     */
    public function testTheRunReopensAtItsOwnCellNotTheFirst(): void
    {
        $html = $this->html("| x | a `b |\n+ y | c | d` |\n");

        $this->assertStringContainsString('<td>x y</td><td>a <code>b c | d</code></td>', $html, $html);
    }

    /**
     * THE FAST PATH HAS TO KNOW. A continuation row carrying no backtick of its
     * own still sits inside a run, so the "no backticks, every pipe is a
     * delimiter" shortcut is wrong for it - and it is the one row shape that
     * reaches the shortcut with a run open.
     */
    public function testAContinuationRowWithNoBacktickOfItsOwnStaysInTheRun(): void
    {
        $html = $this->html("| a `b |\n+ c | d |\n");

        $this->assertStringContainsString('<td>a <code>b c | d</code></td>', $html, $html);
    }

    /**
     * THE WIDTH IS CARRIED, not just the fact that something is open: only a
     * run of the SAME length closes it, so a single backtick inside a doubled
     * run is content and the row still ends at the doubled closer.
     */
    public function testOnlyAMatchingWidthClosesTheRunAcrossTheRow(): void
    {
        $html = $this->html("| a ``b ` |\n+ c | d`` |\n");

        $this->assertStringContainsString('<code>b ` c | d</code>', $html, $html);
    }

    /**
     * The width row that DISCRIMINATES, and a deliberate divergence.
     *
     * With the single backtick on the CONTINUATION row, carrying "something is
     * open" instead of "a run of width two is open" closes the span there and
     * the pipe after it becomes a delimiter again. §19 closes a run only at a
     * delimiter of its own length, so it does not: the pipe is content and the
     * doubled run closes the cell.
     *
     * carve-rs renders `a <code>b c ` d</code>` here and drops `| e` from the
     * document. Keeping author text is not the tie-breaker on its own - the
     * clause is - but an answer that loses content is not the one to copy.
     */
    public function testASingleBacktickDoesNotCloseADoubledRunOnTheNextRow(): void
    {
        $html = $this->html("| a ``b |\n+ c ` d | e`` |\n");

        $this->assertStringContainsString('<code>b c ` d | e</code>', $html, $html);
    }

    /**
     * THE SOURCE CHUNKS SPLIT THE SAME WAY THE CONTENT DID.
     *
     * The chunk walk exists to say WHERE each cell's text came from, so a
     * division that differs from the one that produced the text describes a row
     * that was never built. Split without the inherited run, the pipe inside it
     * put a chunk on a cell index that does not exist, the rebuilt cell's
     * joined-content check failed, and every inline in the row came back with
     * NO POSITION - six nodes of nine, and the whole suite still green, because
     * positions are off by default. Raised by codex review.
     *
     * The merged CELL itself declines a position, which is deliberate: its
     * content is no longer a run of any one line.
     */
    public function testTheRebuiltCellKeepsInlinePositions(): void
    {
        $document = (new BlockParser(trackPositions: true))->parse("| a `b |\n+ c | d` /e/ |\n");

        $missing = [];
        $walk = static function (Node $node) use (&$walk, &$missing): void {
            if ($node->getPos() === null) {
                $missing[] = (new ReflectionClass($node))->getShortName();
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($document);

        $this->assertSame(['TableCell'], $missing, implode(', ', $missing));
    }
}
