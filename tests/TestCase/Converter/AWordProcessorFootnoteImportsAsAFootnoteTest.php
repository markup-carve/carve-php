<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Footnote-shaped HTML from a word processor imports as real footnotes.
 *
 * The `word` and `google-docs` adapter names existed and dispatched nothing, so
 * every one of these documents imported as a literal link beside an orphaned
 * list: the reference kept its `#fn1` href and the note body became an ordinary
 * list item or paragraph.
 *
 * None of these producers uses the DPUB-ARIA roles the importer already reads.
 * What all of them share is a MUTUALLY LINKED ANCHOR PAIR - the body reference
 * points at the note and the note points back - and that pair, not a vendor
 * class name and not the `fn1`/`fnref1` id convention, is what binds them here.
 *
 * The input shapes are verbatim excerpts of real exports, with the exports'
 * own line-wrapping tabs written as spaces:
 * - Word "Save as Web Page": bjanderson70/sf-cross-cutting-concerns,
 *   CCCDocs/home.htm
 * - Word "Save as Web Page, Filtered": cf-convention.github.io,
 *   Data/cf-documents/cf-governance/cf2_whitepaper_final.html
 * - Google Docs "Download as HTML": Flucille/Flucille,
 *   "Stalins political skills.html"
 * - LibreOffice 24.2 Writer HTML export, generated locally
 * - Pandoc 1.x: jgm/pandoc tests/writer.html at tag 1.19.2.4
 */
class AWordProcessorFootnoteImportsAsAFootnoteTest extends TestCase
{
    /**
     * Word writes `name=` rather than `id=` on both anchors, quotes three
     * attributes three different ways, and brackets the separator in a
     * downlevel-revealed conditional an HTML parser hands back as text.
     */
    protected const WORD_SAVE_AS_WEB_PAGE = <<<'HTML'
    <p class=MsoNormal>Static typing<a
    style='mso-footnote-id:ftn1' href="#_ftn1" name="_ftnref1" title=""><span
    class=MsoFootnoteReference><span style='mso-special-character:footnote'><![if !supportFootnotes]><span
    class=MsoFootnoteReference><span style='font-size:11.0pt'>[1]</span></span><![endif]></span></span></a> matters.</p>
    <div style='mso-element:footnote-list'><![if !supportFootnotes]><br clear=all>
    <hr align=left size=1 width="33%">
    <![endif]>
    <div style='mso-element:footnote' id=ftn1>
    <p class=MsoFootnoteText><a style='mso-footnote-id:ftn1' href="#_ftnref1"
    name="_ftn1" title=""><span class=MsoFootnoteReference><span style='mso-special-character:
    footnote'><![if !supportFootnotes]><span class=MsoFootnoteReference><span
    style='font-size:10.0pt'>[1]</span></span><![endif]></span></span></a>
    Static Object Orient Languages</p>
    </div>
    </div>
    HTML;

    /**
     * The filtered save drops every `mso-element` style, so the wrapper is a
     * bare `<div id="ftn1">` and only the anchors still pair.
     */
    protected const WORD_FILTERED = <<<'HTML'
    <p>Data<a href="#_ftn1" name="_ftnref1" title=""><span class="MsoFootnoteReference">[1]</span></a> centre.</p>
    <div><br clear="all">
    <hr align="left" size="1" width="33%">
    <div id="ftn1">
    <p class="MsoFootnoteText"><a href="#_ftnref1" name="_ftn1" title=""><span class="MsoFootnoteReference">[1]</span></a> NCAS British Atmospheric Data Centre</p>
    </div>
    </div>
    HTML;

    /**
     * Google Docs puts the `<sup>` OUTSIDE the anchor, gives every note its own
     * bare `<div>`, and leaves the separator as a body-level sibling.
     */
    protected const GOOGLE_DOCS = <<<'HTML'
    <p class="c4"><span class="c7">Stalin became General Secretary</span><sup class="c1"><a href="#ftnt1" id="ftnt_ref1">[1]</a></sup><span class="c0">&nbsp;in 1922</span><sup class="c1"><a href="#ftnt2" id="ftnt_ref2">[2]</a></sup><span class="c0">.</span></p><hr class="c10"><div><p class="c5"><a href="#ftnt_ref1" id="ftnt1">[1]</a><span class="c2">&nbsp;General Secretary of the Communist Party.</span></p></div><div><p class="c5"><a href="#ftnt_ref2" id="ftnt2">[2]</a><span class="c2">&nbsp;Roy Medvedev, Let History Judge, Page 3</span></p></div>
    HTML;

    /**
     * LibreOffice names nothing `fn`: the pair is `sdfootnote1anc` against
     * `sdfootnote1sym`, and the id on the wrapper div is a third name again.
     */
    protected const LIBREOFFICE = <<<'HTML'
    <p>Body sentence one<a class="sdfootnoteanc" name="sdfootnote1anc" href="#sdfootnote1sym"><sup>1</sup></a>
    continues.</p>
    <p>Second para<a class="sdfootnoteanc" name="sdfootnote2anc" href="#sdfootnote2sym"><sup>2</sup></a>
    ends.</p>
    <div id="sdfootnote1"><p class="sdfootnote"><a class="sdfootnotesym" name="sdfootnote1sym" href="#sdfootnote1anc">1</a>The
     first note body.</p>
    </div>
    <div id="sdfootnote2"><p class="sdfootnote"><a class="sdfootnotesym" name="sdfootnote2sym" href="#sdfootnote2anc">2</a>Note
     two para one.</p>
     <p class="sdfootnote">Note two para two.</p>
    </div>
    HTML;

    /**
     * Pandoc 1.x: `footnoteRef` in camelCase, no ARIA roles anywhere, and a
     * back-link carrying no attributes at all.
     */
    protected const PANDOC_1X = <<<'HTML'
    <p>Here is a footnote reference,<a href="#fn1" class="footnoteRef" id="fnref1"><sup>1</sup></a> and another.</p>
    <div class="footnotes">
    <hr />
    <ol>
    <li id="fn1"><p>Here is the footnote.<a href="#fnref1">&#8617;</a></p></li>
    </ol>
    </div>
    HTML;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function producerProvider(): array
    {
        return [
            'word save as web page' => [self::WORD_SAVE_AS_WEB_PAGE, 'Static Object Orient Languages'],
            'word filtered' => [self::WORD_FILTERED, 'NCAS British Atmospheric Data Centre'],
            'google docs' => [self::GOOGLE_DOCS, 'General Secretary of the Communist Party.'],
            'libreoffice' => [self::LIBREOFFICE, 'The first note body.'],
            'pandoc 1.x' => [self::PANDOC_1X, 'Here is the footnote.'],
        ];
    }

    /**
     * @param string $html
     * @param string $body
     */
    #[DataProvider('producerProvider')]
    public function testTheNoteBecomesADefinitionAndTheReferenceBindsToIt(string $html, string $body): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertStringContainsString('[^1]', $imported);
        $this->assertStringContainsString('[^1]: ', $imported);
        $this->assertStringContainsString($body, $imported);
    }

    /**
     * A back-link is generated navigation, not content. Carried into the body
     * it renders as a stray link to a fragment that no longer exists, and the
     * marker it wraps (`[1]`, `1`, the return arrow) lands in the note's text.
     *
     * @param string $html
     * @param string $body
     */
    #[DataProvider('producerProvider')]
    public function testTheBacklinkAndItsMarkerDoNotReachTheNoteBody(string $html, string $body): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertStringNotContainsString('#_ftnref', $imported);
        $this->assertStringNotContainsString('#fnref', $imported);
        $this->assertStringNotContainsString('#ftnt_ref', $imported);
        $this->assertStringNotContainsString('#sdfootnote1anc', $imported);
        $this->assertStringNotContainsString('↩', $imported);
        $this->assertStringNotContainsString('[^1]: [1]', $imported);
        $this->assertStringNotContainsString('[^1]: 1', $imported);
    }

    /**
     * Every producer emits a rule between the body and the notes, and it is
     * chrome: Pandoc inside the section, Word inside the footnote-list div
     * bracketed by a downlevel conditional, Google Docs as a plain sibling.
     *
     * @param string $html
     * @param string $body
     */
    #[DataProvider('producerProvider')]
    public function testTheSeparatorDoesNotImportAsAThematicBreak(string $html, string $body): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertStringNotContainsString('---', $imported);
        $this->assertStringNotContainsString('supportFootnotes', $imported);
    }

    /**
     * @param string $html
     * @param string $body
     */
    #[DataProvider('producerProvider')]
    public function testTheImportRendersBackAsAFootnote(string $html, string $body): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('role="doc-noteref"', $rendered);
        $this->assertStringContainsString('role="doc-endnotes"', $rendered);
        $this->assertStringContainsString('<li id="fn1">', $rendered);
        $this->assertStringContainsString($body, $rendered);
    }

    /**
     * The adapter is the caller's declaration of provenance. `generic` takes
     * arbitrary HTML, where a mutually linked anchor pair is not proof of a
     * footnote, so it keeps reading only the roles a Carve engine writes.
     */
    public function testTheGenericAdapterLeavesTheShapeAlone(): void
    {
        $imported = (new HtmlToCarve())->convert(self::LIBREOFFICE);

        $this->assertStringNotContainsString('[^1]', $imported);
        $this->assertStringContainsString('#sdfootnote1sym', $imported);
    }

    public function testBothAdapterNamesRecognizeTheShape(): void
    {
        $word = (new HtmlToCarve(importAdapter: 'word'))->convert(self::GOOGLE_DOCS);
        $docs = (new HtmlToCarve(importAdapter: 'google-docs'))->convert(self::GOOGLE_DOCS);

        $this->assertSame($word, $docs);
        $this->assertStringContainsString('[^1]', $word);
    }

    /**
     * A reference whose target does not exist is not a footnote: nothing binds
     * it and `[^1]` with no definition renders as the literal text `[^1]`,
     * which would lose the href as well. It stays the link the HTML spelled,
     * so nothing is lost and there is nothing to report.
     */
    public function testAReferenceWithNoTargetStaysALink(): void
    {
        $html = '<p>Body<a href="#fn9" class="footnote-ref" id="fnref9"><sup>9</sup></a> tail.</p>';

        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertStringNotContainsString('[^', $imported);
        $this->assertStringContainsString('(#fn9)', $imported);
    }

    /**
     * A definition nothing references stays ordinary content.
     *
     * Importing it as a definition would be worse than it looks: Carve renders
     * an unreferenced definition as NOTHING, so text that was visible in the
     * input would silently vanish from the output while still sitting in the
     * source. As ordinary content it stays visible, and the decision is the
     * same whatever container the producer used.
     */
    public function testAnUnreferencedDefinitionStaysVisibleContent(): void
    {
        $html = '<p>Body<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a> tail.</p>'
            . '<section class="footnotes"><hr /><ol>'
            . '<li id="fn1"><p>Note one.<a href="#fnref1" class="footnote-back">&#8617;</a></p></li>'
            . '<li id="fn2"><p>Nothing points here.</p></li>'
            . '</ol></section>';

        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('[^1]: Note one.', $imported);
        $this->assertStringNotContainsString('[^2]', $imported);
        $this->assertStringContainsString('Nothing points here.', $imported);
        $this->assertStringContainsString('Nothing points here.', $rendered);
    }

    /**
     * Two references to one note both bind to it.
     *
     * Only one of them can be the back-link's target, so the mutual pair that
     * confirms the note cannot confirm the second reference. It binds because
     * it addresses a block already known to be a note - which is why the
     * unmarked Google Docs spelling works as well as the marked Pandoc one.
     *
     * @return array<string, array{0: string}>
     */
    public static function sharedReferenceProvider(): array
    {
        return [
            'marked as footnote-ref' => [
                '<p>A<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>'
                . ' and B<a href="#fn1" class="footnote-ref" id="fnref1-2"><sup>1</sup></a>.</p>'
                . '<section class="footnotes"><ol><li id="fn1"><p>Shared.'
                . '<a href="#fnref1" class="footnote-back">&#8617;</a></p></li></ol></section>',
            ],
            'unmarked, google docs shaped' => [
                '<p>A<sup><a href="#ftnt1" id="ftnt_ref1">[1]</a></sup>'
                . ' and B<sup><a href="#ftnt1" id="ftnt_ref1b">[1]</a></sup>.</p>'
                . '<div><p><a href="#ftnt_ref1" id="ftnt1">[1]</a> Shared.</p></div>',
            ],
        ];
    }

    /**
     * @param string $html
     */
    #[DataProvider('sharedReferenceProvider')]
    public function testTwoReferencesToOneNoteBothBind(string $html): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertSame(1, substr_count($imported, '[^1]: '), 'the note is defined once');
        $this->assertSame(
            2,
            substr_count($imported, '[^1]') - substr_count($imported, '[^1]: '),
            'both references must spell the note',
        );
        $this->assertStringContainsString('A[^1] and B[^1].', $imported);
    }

    /**
     * A note body is block content, not one line: the writer indents the
     * continuation so the paragraphs and the list stay inside the note.
     */
    public function testANoteBodyKeepsItsBlocks(): void
    {
        $html = '<p>A<a href="#fn1" class="footnote-ref" id="fnref1"><sup>1</sup></a>.</p>'
            . '<section class="footnotes"><ol><li id="fn1">'
            . '<p>First para.</p><ul><li>one</li><li>two</li></ul>'
            . '<p>Last para.<a href="#fnref1" class="footnote-back">&#8617;</a></p>'
            . '</li></ol></section>';

        $rendered = (new CarveConverter())->convert(
            (new HtmlToCarve(importAdapter: 'word'))->convert($html),
        );

        $this->assertStringContainsString('<p>First para.</p>', $rendered);
        $this->assertStringContainsString('<li>one</li>', $rendered);
        $this->assertStringContainsString('<p>Last para.', $rendered);
        $this->assertSame(1, substr_count($rendered, '<li id="fn1">'));
        $this->assertStringContainsString('</ul>', substr($rendered, (int)strpos($rendered, '<li id="fn1">')));
    }

    /**
     * Nothing here reads the `fn1`/`fnref1` convention. The pair is resolved
     * through the fragment each anchor addresses, and the label is assigned
     * 1..N over the notes in document order - `_ftn1` and `sdfootnote1sym` are
     * generated navigation an engine regenerates, and neither is a label any
     * Carve source could carry.
     */
    public function testIdsOutsideTheConventionStillPair(): void
    {
        $html = '<p>A<a href="#note-alpha" name="mark-alpha"><sup>*</sup></a>.</p>'
            . '<div id="wrap-alpha"><p><a name="note-alpha" href="#mark-alpha">*</a> Odd-id note.</p></div>';

        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertStringContainsString('A[^1].', $imported);
        $this->assertStringContainsString('[^1]: Odd-id note.', $imported);
        $this->assertStringNotContainsString('note-alpha', $imported);
    }

    /**
     * The notes are numbered by definition order, so a document with several
     * gets one label each rather than all of them colliding on `1`.
     */
    public function testEachNoteGetsItsOwnLabel(): void
    {
        $imported = (new HtmlToCarve(importAdapter: 'google-docs'))->convert(self::GOOGLE_DOCS);

        $this->assertStringContainsString('[^1]', $imported);
        $this->assertStringContainsString('[^2]', $imported);
        $this->assertStringContainsString('[^1]: ', $imported);
        $this->assertStringContainsString('[^2]: ', $imported);
    }

    /**
     * The engine's own HTML already imports through the role branches, and
     * naming an adapter must not double-handle it.
     */
    public function testTheEnginesOwnHtmlStillImportsOnceUnderTheAdapter(): void
    {
        $html = (new CarveConverter())->convert("a[^n] b\n\n[^n]: the note body\n");

        $imported = (new HtmlToCarve(importAdapter: 'word'))->convert($html);

        $this->assertSame(1, substr_count($imported, '[^1]: '));
        $this->assertSame(
            $html,
            (new CarveConverter())->convert($imported),
            "importing the rendered HTML must reproduce it; imported source was:\n" . $imported,
        );
    }
}
