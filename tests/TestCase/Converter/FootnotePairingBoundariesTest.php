<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The edges of the footnote pairing rule, one case per guard.
 *
 * These are the branches the producer fixtures never reach. Each one is a
 * decision that would otherwise sit in the code with nothing able to fail if it
 * were removed: how far a note's block may grow, which end of a mutual pair is
 * the reference, what a note's body may keep, and what the pass must not touch.
 */
class FootnotePairingBoundariesTest extends TestCase
{
    /**
     * One note with a marked back-link, for the cases whose subject is the
     * reference site rather than the note.
     *
     * @var string
     */
    protected const NOTE = '<section class="footnotes"><ol><li id="fn1"><p>The note.'
        . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li></ol></section>';

    protected function importAsWord(string $html): string
    {
        return (new HtmlToCarve(importAdapter: 'word'))->convert($html);
    }

    /**
     * A note's block may not grow into the whole document.
     *
     * Where the fragment lands on inline content with no block of its own, the
     * nearest block is the root the fragment was wrapped in - taking it would
     * move every paragraph in the document into one note.
     */
    public function testANoteBlockThatWouldBeTheWholeFragmentIsRefused(): void
    {
        $html = '<span id="x">loose target</span>'
            . '<p>Body<a href="#x" class="footnote-ref" id="rx"><sup>1</sup></a> tail.</p>';

        $imported = $this->importAsWord($html);

        $this->assertStringNotContainsString('[^1]', $imported);
        $this->assertStringContainsString('Body', $imported);
        $this->assertStringContainsString('loose target', $imported);
    }

    /**
     * The same refusal in a full document, where the climb runs past `<body>`
     * and `<html>` - neither is a definition block, so it leaves the document
     * rather than stopping at one.
     */
    public function testANoteBlockIsRefusedWhenTheClimbLeavesTheDocument(): void
    {
        $html = '<html><body><span id="x">loose target</span>'
            . '<p>Body<a href="#x" class="footnote-ref" id="rx"><sup>1</sup></a> tail.</p></body></html>';

        $imported = $this->importAsWord($html);

        $this->assertStringNotContainsString('[^1]', $imported);
        $this->assertStringContainsString('loose target', $imported);
    }

    /**
     * The guarded climb counts targets addressed by `id` as well as by the
     * legacy `<a name>` the Word and LibreOffice fixtures use, so a wrapper
     * holding one `id`-addressed note is still the note's block.
     */
    public function testTheClimbCountsAnIdAddressedTarget(): void
    {
        $html = '<p>Body<sup><a href="#ftnt1" id="ftnt_ref1">[1]</a></sup> tail.</p>'
            . '<div id="wrap1"><p><a href="#ftnt_ref1" id="ftnt1">[1]</a> First half.</p>'
            . '<p>Second half.</p></div>';

        $rendered = (new CarveConverter())->convert($this->importAsWord($html));

        $this->assertStringContainsString('<li id="fn1">', $rendered);
        $this->assertStringContainsString('First half.', $rendered);
        $this->assertStringContainsString(
            'Second half.',
            substr($rendered, (int)strpos($rendered, '<li id="fn1">')),
            'the wrapper is the note, so its second paragraph stays inside the note',
        );
    }

    /**
     * A reference carrying no id of its own has no pair to read from the other
     * end, and binds on its marker alone.
     */
    public function testAReferenceWithNoIdBindsOnItsMarker(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref"><sup>1</sup></a> tail.</p>'
            . '<section class="footnotes"><ol><li id="fn1"><p>The note.</p></li></ol></section>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('Body[^1] tail.', $imported);
        $this->assertStringContainsString('[^1]: The note.', $imported);
    }

    /**
     * Where both ends of a mutual pair are addressable, the marked end is the
     * reference - document order does not get a say.
     */
    public function testAMarkedReferenceWinsOverDocumentOrder(): void
    {
        $html = '<div id="note"><p><a name="target" href="#ref">1</a> The note.</p></div>'
            . '<p>Body<a href="#target" name="ref" class="footnote-ref"><sup>1</sup></a> tail.</p>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('Body[^1] tail.', $imported);
        $this->assertStringContainsString('[^1]: The note.', $imported);
    }

    /**
     * Where neither end is marked as the reference but one is marked as the
     * back-link, the other end is the reference.
     */
    public function testABackLinkMarkerDecidesTheOtherEndIsTheReference(): void
    {
        $html = '<div id="note"><p><a name="target" href="#ref" class="footnote-back">1</a>'
            . ' The note.</p></div>'
            . '<p>Body<a href="#target" name="ref"><sup>1</sup></a> tail.</p>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('Body[^1] tail.', $imported);
        $this->assertStringContainsString('[^1]: The note.', $imported);
    }

    /**
     * A block holding another note's block is a container, not a note. Keeping
     * both would move one subtree into two places at once.
     */
    public function testABlockHoldingAnotherNoteIsNotItselfANote(): void
    {
        $html = '<p>x<a href="#a" name="ra"><sup>1</sup></a> y<a href="#b" name="rb"><sup>2</sup></a></p>'
            . '<div id="a"><p>outer<a href="#ra">back</a></p>'
            . '<div id="b"><p>inner<a href="#rb">back</a></p></div></div>';

        $imported = $this->importAsWord($html);

        $this->assertSame(1, substr_count($imported, ']: '), 'only the inner block is a note');
        $this->assertStringContainsString('[^1]: inner', $imported);
        $this->assertStringContainsString('outer', $imported);
    }

    /**
     * An ordinary link beside a note is left alone: only an anchor addressing a
     * note becomes a reference to it.
     */
    public function testAnExternalLinkIsNotSweptUp(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>'
            . ' and <a href="https://example.com">a site</a>.</p>'
            . '<section class="footnotes"><ol><li id="fn1"><p>The note.'
            . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li></ol></section>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('[a site](https://example.com)', $imported);
        $this->assertStringContainsString('[^1]: The note.', $imported);
    }

    /**
     * A note's own body may address another note without that link turning
     * into a second reference to it.
     */
    public function testALinkInsideANoteIsNotAReference(): void
    {
        $html = '<p>A<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>'
            . ' B<a href="#fn2" class="footnote-ref" id="fnref2"><sup>2</sup></a>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn1"><p>One, see <a href="#fn2">the other</a>.'
            . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li>'
            . '<li id="fn2"><p>Two.<a href="#fnref2" class="footnote-back">&#8617;</a></p></li>'
            . '</ol></section>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('A[^1] B[^2].', $imported);
        $this->assertStringContainsString('[the other](#fn2)', $imported);
        $this->assertSame(2, substr_count($imported, ']: '));
    }

    /**
     * A genuine link in a note's body survives the back-link sweep.
     */
    public function testAContentLinkInANoteBodySurvives(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>.</p>'
            . '<section class="footnotes"><ol><li id="fn1">'
            . '<p>See <a href="https://example.com/paper">the paper</a>.'
            . '<a href="#fnref1" class="footnote-back">&#8617;</a></p>'
            . '</li></ol></section>';

        $imported = $this->importAsWord($html);

        $this->assertStringContainsString('[the paper](https://example.com/paper)', $imported);
        $this->assertStringNotContainsString('#fnref1', $imported);
    }

    /**
     * The wrapper a back-link sat in goes with it, rather than staying behind
     * as an empty superscript.
     */
    public function testTheWrapperAroundABacklinkGoesWithIt(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>.</p>'
            . '<section class="footnotes"><ol><li id="fn1"><p>The note.'
            . '<sup><a href="#fnref1" class="footnote-back">&#8617;</a></sup></p></li></ol></section>';

        $imported = $this->importAsWord($html);

        $this->assertSame("Body[^1].\n\n[^1]: The note.\n", $imported);
    }

    /**
     * A comment sitting in the container the notes leave behind does not keep
     * that container alive.
     */
    public function testACommentDoesNotKeepTheEmptiedContainerAlive(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>.</p>'
            . '<div class="footnotes"><!-- endnotes --><hr /><ol>'
            . '<li id="fn1"><p>The note.<a href="#fnref1" class="footnote-back">&#8617;</a></p></li>'
            . '</ol></div>';

        $imported = $this->importAsWord($html);

        $this->assertSame("Body[^1].\n\n[^1]: The note.\n", $imported);
    }

    /**
     * A `<sup>` holding more than the reference is not the reference's site.
     *
     * Only a superscript that wraps the anchor and nothing else is replaced
     * with it; one that also carries an element of its own keeps its content,
     * and the reference binds inside it.
     */
    public function testASupHoldingMoreThanTheReferenceSurvives(): void
    {
        $html = '<p>Body<sup><a href="#fn1" class="footnote-ref" id="fnref1">1</a><span>*</span></sup> t.</p>'
            . self::NOTE;

        $imported = $this->importAsWord($html);
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('Body{^[^1]*^} t.', $imported);
        $this->assertStringContainsString('role="doc-noteref"', $rendered);
        $this->assertStringContainsString('*</sup>', $rendered);
    }

    /**
     * The same where the superscript's extra content is text rather than an
     * element - here brackets, which must not read as a wiki link on the way
     * back through the parser.
     */
    public function testASupHoldingTextBesideTheReferenceSurvives(): void
    {
        $html = '<p>Body<sup>[<a href="#fn1" class="footnote-ref" id="fnref1">1</a>]</sup> t.</p>'
            . self::NOTE;

        $imported = $this->importAsWord($html);
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('Body{^[[^1]]^} t.', $imported);
        $this->assertStringContainsString('<sup>[<a id="fnref1" href="#fn1" role="doc-noteref">', $rendered);
    }

    /**
     * A separator written AFTER the notes is chrome too. The search only takes
     * what precedes the first note, so this one survives to pruning, which is
     * what stops it importing as a thematic break.
     */
    public function testASeparatorAfterTheNotesDoesNotSurvive(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a> t.</p>'
            . '<div class="footnotes"><ol><li id="fn1"><p>The note.'
            . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li></ol><hr /></div>';

        $imported = $this->importAsWord($html);

        $this->assertSame("Body[^1] t.\n\n[^1]: The note.\n", $imported);
    }

    /**
     * Notes written before the body still pair, and the separator search stops
     * at the top of the document rather than walking off it.
     */
    public function testNotesWrittenBeforeTheBodyStillPair(): void
    {
        $html = '<html><body>'
            . '<section class="footnotes"><ol><li id="fn1"><p>The note.'
            . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li></ol></section>'
            . '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a> tail.</p>'
            . '</body></html>';

        $imported = $this->importAsWord($html);

        $this->assertSame("Body[^1] tail.\n\n[^1]: The note.\n", $imported);
    }
}
