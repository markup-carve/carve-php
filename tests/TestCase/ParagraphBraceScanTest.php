<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\Node\Block\Paragraph;
use Carve\Node\Node;
use Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Covers the incremental brace scan used while collecting a paragraph.
 *
 * The scan must (a) keep its original semantics - an unclosed attribute brace
 * in the paragraph so far suppresses paragraph interruption - and (b) stay
 * linear in the number of lines. Re-scanning the whole growing content on every
 * line previously made a single multi-line paragraph parse in O(n^2).
 *
 * Note: under the one-rule §10 model NOTHING interrupts an open paragraph except
 * a caption (`^ ` line, a §4 attachment). So these tests use a CAPTION line as
 * the interrupter - the only construct whose interruption the brace scan
 * suppresses while a `{` attribute brace remains open. A caption following a
 * plain paragraph (no captionable image) ends it and forms its own block, so an
 * interruption splits the input into two children; a suppressed one folds into a
 * single paragraph.
 */
class ParagraphBraceScanTest extends TestCase
{
    private function hasInterrupter(Node $doc): bool
    {
        // An interruption ends the first paragraph and starts a second block;
        // a suppressed interruption folds the caption line into one paragraph.
        return count($doc->getChildren()) > 1;
    }

    public function testUnclosedBraceSuppressesInterruption(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=x\n^ caption");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
        $this->assertFalse($this->hasInterrupter($doc));
    }

    public function testClosedBraceAllowsInterruption(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=x}\n^ caption");

        $this->assertTrue($this->hasInterrupter($doc));
    }

    public function testBraceInsideQuoteIsNotCounted(): void
    {
        // The `}` lives inside a quoted value, so the `{` stays unclosed and the
        // caption must not interrupt - exercises quote state carried across the
        // segment boundary.
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=\"}\"\n^ caption");

        $this->assertFalse($this->hasInterrupter($doc));
    }

    public function testPlainParagraphStillInterrupts(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text\n^ caption");

        $this->assertTrue($this->hasInterrupter($doc));
    }

    /**
     * Regression guard for the O(n^2) paragraph scan. A 3000-line single
     * paragraph parses in milliseconds when linear; the previous quadratic
     * behavior took tens of seconds, so a generous absolute bound reliably
     * separates the two without being timing-flaky.
     */
    public function testLargeSingleParagraphParsesInLinearTime(): void
    {
        $lines = [];
        for ($i = 0; $i < 3000; $i++) {
            $lines[] = "continuation line $i of one big paragraph here";
        }
        $source = implode("\n", $lines);

        $parser = new BlockParser();

        $start = hrtime(true);
        $doc = $parser->parse($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertCount(1, $doc->getChildren());
        $this->assertLessThan(3.0, $elapsed, "3000-line paragraph took {$elapsed}s (expected sub-second; quadratic regression?)");
    }
}
