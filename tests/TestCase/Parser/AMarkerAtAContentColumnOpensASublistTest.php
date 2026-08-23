<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A marker at an item's content column opens a sublist, first in the item or not.
 *
 * PART 9 §24 C3: "AT content_column: dedented to the body's column 0, a block
 * opener nests and a list marker opens a sublist", holding "whether or not a
 * blank line precedes the child". §10 I2 defers to it by name - "TIGHT NESTED
 * LISTS UNAFFECTED ... that is §24 C3 (content column), not this relation".
 *
 * The collector answered it for the FIRST marker in an item only, by injecting a
 * synthetic blank before it while no marker had been seen. So two documents
 * differing only by a sub-list that had already been closed disagreed about what
 * their shared last line was - an answer depending on a container that had
 * already ended (markup-carve/carve#1517).
 *
 * The executable spec has read it the other way all along:
 * `tests/spec/scripts/spec/layout.mjs` breaks the paragraph on
 * `inItem && para.length > 0 && matchMarkerAt(ind(i))`, cited to §24 C3. All
 * three engines diverged from it identically, which is why nothing reported it.
 */
class AMarkerAtAContentColumnOpensASublistTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testItOpensOneBelowAParagraphWhenASublistHasAlreadyClosed(): void
    {
        // THE REPRODUCTION, and it has no table in it. The ticket used one,
        // which made the cause look like something about tables; a blank line
        // closes the sub-list just as well and isolates it.
        $this->assertSame(
            '<ul> <li><p>o</p> <ul> <li>z</li> </ul> <p>para</p> <ul> <li>s1</li> </ul> </li> </ul>',
            $this->html("- o\n  - z\n\n  para\n  - s1\n"),
        );
    }

    public function testTheSameDocumentWithoutTheSublistAgrees(): void
    {
        // The pair: one line shorter, and it always opened a sublist because
        // `- s1` was then the item's FIRST marker.
        $this->assertSame(
            '<ul> <li><p>o</p> <p>para</p> <ul> <li>s1</li> </ul> </li> </ul>',
            $this->html("- o\n\n  para\n  - s1\n"),
        );
    }

    public function testTheTicketsOwnSpellingWithATable(): void
    {
        $this->assertSame(
            '<ul> <li>o <ul> <li>z</li> </ul> <table> <tbody> <tr><td>a</td></tr> </tbody> </table> para <ul> <li>s1</li> </ul> </li> </ul>',
            $this->html("- o\n  - z\n  | a |\n  para\n  - s1\n"),
        );
    }

    public function testAnOrderedMarkerToo(): void
    {
        // §24 C3 spells the child set "SYMMETRIC", so bullet, task and ordered
        // behave alike here exactly as they do when they fold everywhere else.
        $this->assertSame(
            '<ul> <li><p>o</p> <ul> <li>z</li> </ul> <p>para</p> <ol> <li>s1</li> </ol> </li> </ul>',
            $this->html("- o\n  - z\n\n  para\n  1. s1\n"),
        );
    }

    public function testATaskMarkerAndTheAbuttingAttributeForm(): void
    {
        $this->assertStringContainsString('<input', $this->html("- o\n  - z\n\n  para\n  - [ ] s1\n"));
        $this->assertStringContainsString('class="k"', $this->html("- o\n  - z\n\n  para\n  -{.k} s1\n"));
    }

    public function testASiblingMarkerStaysASiblingAndStaysTight(): void
    {
        // The control the old guard existed to protect: a marker of the sublist
        // already open is not a new child of the item, and must not be given a
        // loosening separator.
        $this->assertSame(
            '<ul> <li>o <ul> <li>z</li> <li>w</li> </ul> </li> </ul>',
            $this->html("- o\n  - z\n  - w\n"),
        );
        $this->assertSame(
            '<ul> <li>o <ul> <li>z para</li> <li>s1</li> </ul> </li> </ul>',
            $this->html("- o\n  - z\n  para\n  - s1\n"),
        );
    }

    public function testColumnZeroIsUnchanged(): void
    {
        // §24 C3 is a divergence for the CONTENT column. The top level is §10 I2
        // and does not move.
        $this->assertSame(
            '<table> <tbody> <tr><td>a</td></tr> </tbody> </table> <p>para - s1</p>',
            $this->html("| a |\npara\n- s1\n"),
        );
    }

    public function testBelowTheContentColumnAMarkerStillFolds(): void
    {
        // §24 C3's other band: "BELOW content_column ... a list marker folds as
        // lazy item text". Corpus 05-lists-8.
        $this->assertSame('<ol> <li>outer 1. inner</li> </ol>', $this->html("1. outer\n  1. inner\n"));
    }

    /**
     * NOW FIXED, AND THE PIN MOVED WITH IT (markup-carve/carve-php#1575). This
     * test was written to FAIL when the divergence was closed, and asserted
     *
     *     <ul> <li> <blockquote><p>q</p></blockquote> <ul> <li>s tail</li> </ul> </li> </ul>
     *
     * - this engine's own answer, on purpose, because markup-carve/carve#1517
     * moved nothing here and a test asserting the right answer would have failed
     * for the wrong reason. §10 I6 was the guard that did move it: a quote's OPEN
     * paragraph claims the line before the item's content column does, so the
     * marker is text. The row now asserts what carve-js, carve-rs and the
     * executable spec have always produced, and it keeps its place here as the
     * boundary of §24 C3 rather than an instance of it.
     */
    public function testAMarkerOnAQuoteLazyContinuationIsNotWhatThisRuleDecides(): void
    {
        $this->assertSame(
            '<ul> <li> <blockquote><p>q - s tail</p></blockquote> </li> </ul>',
            $this->html("- > q\n  - s\ntail\n"),
        );
    }
}
