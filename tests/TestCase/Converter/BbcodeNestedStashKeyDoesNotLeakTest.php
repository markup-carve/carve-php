<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A nested literal run does not leak its stash key (markup-carve/carve-php#1611).
 *
 * BbcodeToCarve hides two families of literal content behind a private-use key
 * and used to put them back in ONE pass. The families are stashed in turn - the
 * code runs first, [noparse] second - so a [noparse] body can hold a key of its
 * own, and a single preg_replace_callback continues scanning after each
 * replacement and never re-reads what it just wrote. The raw private-use pair
 * reached the output.
 *
 * That is a sentinel escaping into user-visible text, which is the shape worth
 * being strictest about: it is invisible in a terminal, so every assertion here
 * checks the BYTES and then checks separately that no private-use character
 * survived at all.
 *
 * Found by the carve-js agent while porting [noparse] there
 * (markup-carve/carve-js#1375), which restores in the same bounded loop.
 */
class BbcodeNestedStashKeyDoesNotLeakTest extends TestCase
{
    private BbcodeToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeToCarve();
    }

    /**
     * Assert no character from the private-use area reached the output.
     */
    private function assertNoPrivateUse(string $out): void
    {
        $this->assertSame(
            0,
            preg_match('/[\x{E000}-\x{F8FF}]/u', $out),
            'a private-use stash sentinel reached the output: ' . json_encode($out),
        );
    }

    /**
     * THE DEFECT. Both tags are consumed, the code run stays literal, and the
     * key that was spliced into the [noparse] body is put back too.
     */
    public function testNoparseOverACodeRunRestoresBothSpans(): void
    {
        $out = $this->converter->convert('[noparse][code]x[/code][/noparse]');
        $this->assertSame("[code]x[/code]\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * The inline family nests the same way and was equally exposed.
     */
    public function testNoparseOverAnInlineCodeRunRestoresBothSpans(): void
    {
        $out = $this->converter->convert('[noparse][c]y[/c][/noparse]');
        $this->assertSame("[c]y[/c]\n", $out);
        $this->assertNoPrivateUse($out);

        $out = $this->converter->convert('[noparse][icode]z[/icode][/noparse]');
        $this->assertSame("[icode]z[/icode]\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * More than one nested run in one document, so the loop has to keep going
     * past the pass that resolved the first. A single extra pass would fix the
     * one-span case and still leak here.
     */
    public function testSeveralNestedRunsAllComeBack(): void
    {
        $out = $this->converter->convert(
            '[noparse][code]a[/code][/noparse] and [noparse][code]b[/code][/noparse]',
        );
        $this->assertSame("[code]a[/code] and [code]b[/code]\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * BOUND, the direction that was already correct: the code content is
     * stashed FIRST, so the [noparse] pass never sees inside it and the tags
     * stay literal in the fence. A fix that restored too eagerly, or that
     * re-ran the stash, would move this row.
     */
    public function testTheReverseNestingIsUnchanged(): void
    {
        $out = $this->converter->convert('[code][noparse]y[/noparse][/code]');
        $this->assertSame("```\n[noparse]y[/noparse]\n```\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * BOUND: the unnested cases the single pass already handled must not move.
     */
    public function testTheUnnestedRunsAreUnchanged(): void
    {
        $out = $this->converter->convert('[noparse][b]x[/b][/noparse]');
        $this->assertSame("[b]x[/b]\n", $out);
        $this->assertNoPrivateUse($out);

        $out = $this->converter->convert('[code][b]not bold[/b][/code]');
        $this->assertSame("```\n[b]not bold[/b]\n```\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * BOUND: a document with no literal run at all still restores nothing, and
     * the loop's empty-stash short circuit still returns the text untouched.
     */
    public function testADocumentWithNoLiteralRunIsUntouched(): void
    {
        $out = $this->converter->convert('[b]bold[/b]');
        $this->assertSame("*bold*\n", $out);
        $this->assertNoPrivateUse($out);
    }

    /**
     * A private-use character the AUTHOR wrote is not a key and must survive.
     * DocumentSentinels::pick() steps off any run the input occupies, so the
     * loop cannot mistake authored text for a slot - and the guard above would
     * otherwise be checking the author's own character.
     */
    public function testAnAuthoredPrivateUseCharacterSurvives(): void
    {
        $out = $this->converter->convert("[noparse][code]\u{E010}0\u{E011}[/code][/noparse]");

        // COMPARED AS HEX, because both sides are invisible either way. The
        // leaked key and the author's own character occupy the same private-use
        // area, so a byte comparison that failed here printed two strings that
        // looked identical - which is the same reason the defect survived in
        // the first place.
        $this->assertSame(
            bin2hex("[code]\u{E010}0\u{E011}[/code]\n"),
            bin2hex($out),
        );
    }
}
