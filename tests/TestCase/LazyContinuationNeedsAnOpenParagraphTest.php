<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 1 S4 makes lazy continuation conditional on an OPEN PARAGRAPH:
 *
 *   "if ANY container in the open stack holds an OPEN PARAGRAPH and the residue
 *   is NOT an interrupting line, L folds into the INNERMOST such paragraph and
 *   NOTHING closes. Otherwise close the unmatched containers and re-classify
 *   the residue in the surviving context."
 *
 * This engine kept the quote open after three constructs that leave no
 * paragraph behind: a heading, a definition term and a footnote definition.
 * carve-rs applies the condition in every case; carve-js shares two of the
 * three (carve-js#554). The majority was wrong here, which is why these assert
 * against S4 rather than against the other engines (carve-php#652).
 */
class LazyContinuationNeedsAnOpenParagraphTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testAnOpenParagraphStillFolds(): void
    {
        // The control. Pinned by corpus 82-blockquote-lazy-continuation.
        $this->assertSame(
            '<blockquote><p>a b</p></blockquote>',
            $this->squash($this->converter->convert("> a\nb\n")),
        );
    }

    public function testAHeadingLeavesNoParagraphToFoldInto(): void
    {
        // PART 9 §10 I6 names this one twice over: "HEADING is the SOLE
        // exception: a bounded title holds no block and ENDS AT THE NEWLINE,
        // so nothing folds into it at all."
        $this->assertSame(
            '<blockquote> <h1 id="h">h</h1> </blockquote> <p>b</p>',
            $this->squash($this->converter->convert("> # h\nb\n")),
        );
    }

    public function testADefinitionTermLeavesNoParagraphToFoldInto(): void
    {
        // A term is a bounded single line like a heading - it holds inline
        // content, not a paragraph - so a lazy line cannot extend it.
        //
        // The space after `>` is REQUIRED (carve#525). This case was written
        // as `>:: t` while that was still a quote, and the rule landed one
        // minute before this file did - so main went red on a test whose own
        // PR had been green against the older base.
        $this->assertSame(
            '<blockquote> <dl> <dt>t</dt> </dl> </blockquote> <p>~</p>',
            $this->squash($this->converter->convert("> :: t\n~\n")),
        );
    }

    public function testAFootnoteDefinitionLeavesNoParagraphToFoldInto(): void
    {
        // An invisible construct leaves no paragraph at all.
        $this->assertSame(
            '<blockquote> </blockquote> <p>/</p>',
            $this->squash($this->converter->convert("> [f]: ~\n/\n")),
        );
    }

    public function testAClosedFenceAlreadyClosedTheQuote(): void
    {
        // Already correct in every engine; here so a fix that reached for
        // "always close" instead of "close when no paragraph is open" does not
        // pass by accident.
        $html = $this->squash($this->converter->convert("> ```\n> x\n> ```\nb\n"));

        $this->assertStringContainsString('</blockquote> <p>b</p>', $html);
    }
}
