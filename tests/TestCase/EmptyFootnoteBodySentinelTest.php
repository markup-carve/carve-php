<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §7b: a footnote definition with no blocks is written `[^f]: {empty}`.
 *
 * The body empties whenever the definition line's whole body is a
 * block-attribute run: the line collects it as attributes and, with no
 * following block inside the note, drops it. The writer then has a definition
 * to spell and nothing to put after the colon.
 *
 * `[^f]:` is the wrong answer, and it is the one this engine gave. That line is
 * not a definition at all - MARKER REQUIRES CONTENT (PART 2) - so the output
 * loses BOTH halves: the definition comes back as a paragraph and the reference
 * to it comes back as literal text. PART 11 §1a is the rule that licenses the
 * departure: the emitted bytes must re-parse to the tree they were written
 * from, and where the per-construct spelling cannot do that, §1 wins.
 *
 * THE MUTATION THESE ROWS EXIST FOR is emitting the bare marker. Reverting the
 * writer to `'[^' . $label . ']: '` with an empty body breaks every test below
 * except the control.
 *
 * WHY NOT `{ }` OR `{}` - the two spellings a reader reaches for first. Neither
 * is an attribute block on a block line: `block_attributes` requires at least
 * one attribute and there is no block-level blessed-empty form, so both stay
 * CONTENT and the note's body then holds a text node the author never wrote.
 * That is the same §1 failure as the bare marker in a different shape, which is
 * why one row here reads the rendered BODY rather than merely asserting that a
 * definition was produced - a check for "is there an endnote section" passes
 * for every candidate including the ones that do not work.
 */
class EmptyFootnoteBodySentinelTest extends TestCase
{
    protected CarveConverter $converter;

    /**
     * A definition whose body is a block-attribute line, so the body empties.
     */
    protected string $source = "[^f]: {x}\n\nr[^f]\n";

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testAnEmptyBodyIsWrittenWithTheSentinel(): void
    {
        $this->assertSame("r[^f]\n\n[^f]: {empty}\n", CarveConverter::toCarve($this->source));
    }

    public function testTheWrittenSourceStillDefinesAndStillResolves(): void
    {
        $written = CarveConverter::toCarve($this->source);

        // Reading the BODY, not merely that an endnote section exists: the
        // reference resolves to a numbered noteref, and the note holds the
        // backlink and nothing else.
        $this->assertSame(
            '<p>r<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p> '
            . '<section role="doc-endnotes"> <hr> <ol> <li id="fn1"> '
            . '<p><a href="#fnref1" role="doc-backlink">↩</a></p> </li> </ol> </section>',
            $this->squash($this->converter->convert($written)),
        );
    }

    public function testFormattingPreservesTheDocument(): void
    {
        // PART 11 §1, in the form a person can see. The bare marker fails here:
        // it renders `<p>r[^f]</p> <p>[^f]:</p>`, both halves literal.
        $this->assertSame(
            $this->squash($this->converter->convert($this->source)),
            $this->squash($this->converter->convert(CarveConverter::toCarve($this->source))),
        );
    }

    public function testTheWriterSettles(): void
    {
        $once = CarveConverter::toCarve($this->source);
        $this->assertSame($once, CarveConverter::toCarve($once));
    }

    public function testTheSentinelReachesNothingItPassesOverOnTheWayOut(): void
    {
        // `{empty}` is a BOOLEAN ATTRIBUTE and renders `empty=""` wherever
        // attributes survive. It is inert on this node because a footnote body
        // is its own container, and that has to hold for the neighbours too:
        // neither the next definition nor a following paragraph may collect it.
        $written = CarveConverter::toCarve("r[^f] s[^g]\n\n[^f]: {empty}\n\n[^g]: g body\n");
        $html = $this->converter->convert($written);

        $this->assertStringNotContainsString('empty=', $html);
        $this->assertStringContainsString('g body', $html);
        $this->assertSame(2, substr_count($html, 'doc-backlink'));
    }

    public function testABareWordAttributeIsOtherwiseLoadBearing(): void
    {
        // The reason the clause says the inertness is a PARSE RULE rather than
        // a property of the word. Same token, one node over, and it renders.
        $this->assertSame('<p empty="">para</p>', $this->squash($this->converter->convert("{empty=\"\"}\npara\n")));
    }

    public function testAnOrdinaryBodyIsUnchangedControl(): void
    {
        // CONTROL. This passes today, no mutation above touches it, and
        // without it the rows are equally satisfied by a writer that put
        // `{empty}` on every footnote definition it ever wrote.
        $this->assertSame("r[^f]\n\n[^f]: t\n", CarveConverter::toCarve("r[^f]\n\n[^f]: t\n"));
    }
}
