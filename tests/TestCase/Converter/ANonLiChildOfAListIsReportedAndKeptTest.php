<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A non-`li` child of a list keeps its content, and says where it went.
 *
 * The item loop acted only on `li` children and had no `else`, so the WHOLE of
 * anything else a list carried left the document: `<ul><div id="stray">z</div>`
 * `<li>a</li></ul>` came back as one item, the text `z` gone, and the report
 * empty - not `element-dropped`, not `element-unwrapped`, nothing.
 *
 * The answer is both halves. Every non-item child goes through the ORDINARY
 * block walk rather than being unwrapped by hand, so a `<div id="stray">` comes
 * back as a Carve div still carrying its id; the blocks are emitted AHEAD of
 * the list, which is the call `<dd>`-with-no-`<dt>` already makes, because a
 * list holding a non-item has no Carve spelling at all. The code is
 * `element-unwrapped` at `warning`: a structural note about the input that
 * loses no meaning. No engine spells "moved", and inventing a vocabulary entry
 * for it would be a three-engine decision rather than this defect's.
 *
 * The ruling is markup-carve/carve-rs#1266; carve-js carries it as
 * markup-carve/carve-js#1340.
 */
class ANonLiChildOfAListIsReportedAndKeptTest extends TestCase
{
    /**
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(string $html): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [
                'code' => $diagnostic->code,
                'message' => $diagnostic->message,
                'severity' => $diagnostic->severity,
                'path' => $diagnostic->path,
            ],
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    protected function carve(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * The div survives as a div, with its id, ahead of the list - and one row
     * says so, at the path the node sits at among the LIST's children rather
     * than at its place in a filtered array.
     */
    public function testAStrayDivKeepsItsElementAndItsIdAheadOfTheList(): void
    {
        $html = '<ul><div id="stray">z</div><li>a</li></ul>';

        $this->assertSame("{#stray}\n:::\nz\n:::\n\n- a\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'A <div> inside <ul> kept its content but not its place among the items:'
                        . ' it is emitted as blocks ahead of the list',
                    'severity' => 'warning',
                    'path' => '/ul[1]/div[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * Bare text directly inside a list is a child node too, and it is the one
     * an element walk never reaches. It comes back as the paragraph it needs.
     */
    public function testBareTextInsideAListComesBackAsAParagraphAheadOfIt(): void
    {
        $html = '<ul>zz<li>a</li></ul>';

        $this->assertSame("zz\n\n- a\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Text directly inside <ul> kept its content but not its place among the items:'
                        . ' it is emitted as a paragraph ahead of the list',
                    'severity' => 'warning',
                    'path' => '/ul[1]/text()[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * An ACTIVE element is not kept, and must not be reported as if it were: a
     * position note beside the drop would tell the reader a script survived
     * ahead of the list. The `element-dropped` every other site gives it is the
     * whole report - which this engine already produced, because its report is
     * a walk of the input rather than a by-product of the conversion.
     */
    public function testAStrayScriptKeepsItsDropAndGetsNoPositionNote(): void
    {
        $html = '<ul><script>evil()</script><li>a</li></ul>';

        $this->assertSame("- a\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-dropped',
                    'message' => 'Dropped active <script> element',
                    'severity' => 'warning',
                    'path' => '/ul[1]/script[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * The margin between pretty-printed items is blank text and a comment is a
     * comment: neither produces a block, so neither produces a note. Delegating
     * to the ordinary walk is what settles this without a rule of its own.
     */
    public function testAMarginAndACommentProduceNeitherBlocksNorRows(): void
    {
        $html = "<ul>\n  <!-- c -->\n  <li>a</li>\n  <li>b</li>\n</ul>";

        $this->assertSame("- a\n- b\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * An ordered list is the same walk, and the numbering of the items is not
     * disturbed by the child that is not one.
     */
    public function testAnOrderedListKeepsItsStrayChildAndItsNumbering(): void
    {
        $html = '<ol><li>a</li><p>mid</p><li>b</li></ol>';

        $this->assertSame("mid\n\n1. a\n2. b\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'A <p> inside <ol> kept its content but not its place among the items:'
                        . ' it is emitted as blocks ahead of the list',
                    'severity' => 'warning',
                    'path' => '/ol[1]/p[2]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * The kept blocks reparse to what they were: the div is a div and the id
     * rides on it, which is the whole reason the walk is delegated to rather
     * than the content unwrapped by hand.
     */
    public function testTheKeptDivReparsesToADivThatStillCarriesTheId(): void
    {
        $carve = $this->carve('<ul><div id="stray">z</div><li>a</li></ul>');

        $html = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('id="stray"', $html);
        $this->assertStringContainsString('<div', $html);
    }
}
