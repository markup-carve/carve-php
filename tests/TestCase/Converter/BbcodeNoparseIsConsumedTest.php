<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * `[noparse]` is consumed and its content stays literal
 * (markup-carve/carve-php#1209).
 *
 * The tag has no Carve construct to become - its whole effect is "the enclosed
 * text is literal" - so keeping it emitted `[noparse]` verbatim into a document
 * that has no such thing, and the cleanup pass then ate the closer, leaving it
 * unbalanced.
 */
class BbcodeNoparseIsConsumedTest extends TestCase
{
    private BbcodeToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeToCarve();
    }

    public function testTheTagsAreConsumed(): void
    {
        $carve = $this->converter->convert('[noparse][b]x[/b][/noparse]');

        $this->assertStringNotContainsString('[noparse]', $carve);
        $this->assertStringNotContainsString('[/noparse]', $carve);
    }

    /**
     * The point of the tag: what it wrapped reaches the reader as itself. These
     * assert through the RENDERER, because "literal" is a claim about output
     * rather than about which escape the converter chose.
     */
    public function testTheContentRendersLiterally(): void
    {
        $render = fn (string $bb): string => trim(
            CarveConverter::create()->convert($this->converter->convert($bb)),
        );

        $this->assertSame('<p>[b]x[/b]</p>', $render('[noparse][b]x[/b][/noparse]'));
        $this->assertSame('<p>a *b* c</p>', $render('[noparse]a *b* c[/noparse]'));
        $this->assertSame('<p>/it/</p>', $render('[noparse]/it/[/noparse]'));
    }

    /**
     * Mixed, and it says so rather than claiming to be a bound: the first row
     * FAILS against the previous converter, because `[noparse]plain[/noparse]`
     * used to leak its opening tag even with nothing to escape. Only the second
     * row - markup outside the tag still converting - is a true bound.
     */
    public function testPlainContentAndOutsideMarkupAreUnaffected(): void
    {
        $this->assertSame("plain\n", $this->converter->convert('[noparse]plain[/noparse]'));
        $this->assertSame("*bold*\n", $this->converter->convert('[b]bold[/b]'));
    }

    /**
     * BOUND: `[code]` is the neighbouring case and keeps its fence. A fix that
     * folded noparse into the code path would turn this into a fence too.
     */
    public function testCodeStillBecomesAFence(): void
    {
        $this->assertSame("```\n[b]y[/b]\n```\n", $this->converter->convert('[code][b]y[/b][/code]'));
    }
}
