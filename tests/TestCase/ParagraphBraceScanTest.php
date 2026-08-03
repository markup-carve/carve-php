<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Paragraph collection: interruption does not depend on brace state, and the
 * scan stays linear.
 *
 * This file used to assert the opposite of its first two cases. An unclosed
 * attribute brace suppressed block interruption, so every line after `text{a=x`
 * became paragraph text until a blank line - which published COMMENT bodies and
 * swallowed headings and fences.
 *
 * That rule was carve-php's alone: carve-js and carve-rs interrupt normally
 * after `text{a=x`, and PART 9 §10's I1 says nothing about brace state. It also
 * protected nothing, since an inline attribute block cannot span lines in any
 * engine. The behaviour and these cases were corrected together; see
 * BraceDoesNotSuppressInterruptionTest.
 *
 * The linearity guard below is the part that stays: re-scanning the whole
 * growing content on every line previously made a single multi-line paragraph
 * parse O(n^2).
 */
class ParagraphBraceScanTest extends TestCase
{
    private function hasHeading(Node $doc): bool
    {
        foreach ($doc->getChildren() as $child) {
            if ($child instanceof Heading) {
                return true;
            }
        }

        return false;
    }

    public function testUnclosedBraceDoesNotSuppressInterruption(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=x\n# heading");

        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
        $this->assertTrue($this->hasHeading($doc), 'the heading interrupts, as in carve-js and carve-rs');
    }

    public function testClosedBraceAllowsInterruption(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=x}\n# heading");

        $this->assertTrue($this->hasHeading($doc));
    }

    public function testAQuotedBraceDoesNotChangeInterruption(): void
    {
        // Whatever the brace state, the heading interrupts. Kept as a case
        // because it is the shape that made the old rule look subtle.
        $parser = new BlockParser();
        $doc = $parser->parse("text{a=\"}\"\n# heading");

        $this->assertTrue($this->hasHeading($doc));
    }

    public function testPlainParagraphStillInterrupts(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("text\n# heading");

        $this->assertTrue($this->hasHeading($doc));
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
