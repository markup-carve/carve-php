<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note nested in another note's body has its floor at two columns past ITS
 * OWN marker, and a line below that floor belongs to the nearest surviving
 * ancestor.
 *
 * PART 9 §16 puts a note body two columns past the definition, and PART 0's
 * OWNER SELECTION FOR THE NEXT VISIBLE LINE hands a line at or below a frame's
 * base column to the nearest surviving ancestor. Together they place a trailing
 * line between an inner note's marker and its floor in the OUTER note, which is
 * what markup-carve/carve#1971 ruled.
 *
 * This engine measured the floor on the coordinate system the body parse runs
 * in rather than on the authored source. A nested definition arrives there
 * already dedented to the column it reaches, so its marker reads as flush and
 * the fixed floor of two let the inner note claim lines the outer one owns. The
 * authored columns are still on `sourceLines`, so the floor is measured there
 * and the dedent - which is how the later re-collect finds the definition in
 * the right body - is left alone.
 *
 * ONLY A NOTE INSIDE ANOTHER NOTE'S BODY is measured this way. A note in a list
 * item or a description body is placed by its host's content column and has
 * always been read correctly here; carve-js#1666 draws the same line with its
 * `hostIsFootnoteBody` parameter, and the control below pins it.
 *
 * Every expectation was compared against carve-js `6b050a68` and carve-rs
 * `ac7383d3`, both built from source, across all 197 cells of the ticket's band
 * grid and all 600 of its wider grid - byte-identical to both.
 */
class ANestedNoteFloorIsMeasuredAtItsAuthoredMarkerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * The text runs of each note, in document order, so `fn1` is the outer note
     * and `fn2` the nested one.
     *
     * Read from the published tree rather than the HTML: a backlink's
     * `aria-label` carries prose of its own, and matching on the rendered
     * markup gave a wrong matrix while this was being measured.
     *
     * @return array<int, array<string>>
     */
    private function noteRuns(string $source): array
    {
        $notes = [];
        $text = function (array $node) use (&$text, &$runs): void {
            if (($node['type'] ?? '') === 'text') {
                $runs[] = $node['value'];

                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                $text($child);
            }
        };
        $find = function (array $node) use (&$find, $text, &$notes, &$runs): void {
            if (($node['type'] ?? '') === 'footnote') {
                $runs = [];
                $text($node);
                $notes[] = $runs;

                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                $find($child);
            }
            foreach ($node['items'] ?? [] as $child) {
                $find($child);
            }
        };
        $find((new AstCodec())->encode($this->converter->parse($source)));

        return $notes;
    }

    /**
     * The band the ticket names: an inner note holding a definition, with a
     * trailing line between the inner marker and the inner floor.
     *
     * @return array<string, array{int, int}>
     */
    public static function belowTheFloor(): array
    {
        $cases = [];
        foreach ([5, 6, 7] as $marker) {
            foreach (range(4, $marker - 1) as $tail) {
                $cases["marker at $marker, tail at $tail"] = [$marker, $tail];
            }
        }

        return $cases;
    }

    private function band(int $marker, int $tail): string
    {
        return "[^f]: outer\n\n"
            . str_repeat(' ', $marker) . "[^g]: mid\n\n"
            . str_repeat(' ', $marker + 2) . "[r]: /url\n"
            . str_repeat(' ', $tail) . "TAILWORD\n\n"
            . "x[^f] [^g] [t][r]\n";
    }

    #[DataProvider('belowTheFloor')]
    public function testATrailingLineBelowTheNestedFloorBelongsToTheOuterNote(int $marker, int $tail): void
    {
        $source = $this->band($marker, $tail);

        $this->assertSame(
            [['outer', 'TAILWORD'], ['mid']],
            $this->noteRuns($source),
            'the trailing line sits below the nested note\'s floor',
        );
        $this->assertStringContainsString(
            'href="/url"',
            $this->converter->convert($source),
            'the nested definition stopped registering, so placement was bought with a resolution',
        );
    }

    /**
     * THE OTHER SIDE OF THE SAME FLOOR, which never moved: at or past two
     * columns down the line is the nested note's own.
     */
    public function testATrailingLineAtTheNestedFloorStaysInTheNestedNote(): void
    {
        $source = $this->band(5, 7);

        $this->assertSame([['outer'], ['mid', 'TAILWORD']], $this->noteRuns($source));
    }

    /**
     * THE BOUND ON THE MEASUREMENT. A note in a description body is placed by
     * the `dd`'s content column, and reading its floor off the authored line
     * would read the `:` marker's column instead of the note's - so the
     * measurement is asked for only inside another note's body.
     */
    public function testANoteInADescriptionBodyIsUnaffected(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>b</dd>\n</dl>\n"
                . "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>a<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">\u{21a9}</a></p>\n"
                . "    </li>\n  </ol>\n</section>\n",
            $this->converter->convert(":: t\n:  [^1]: a\n    b\n\nsee[^1]\n"),
        );
    }
}
