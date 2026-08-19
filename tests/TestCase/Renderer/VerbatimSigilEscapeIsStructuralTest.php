<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TWO SIGILS PREFIX A VERBATIM RUN: `!` opens an inline literal (PART 9 §27)
 * and `$` opens inline math, which §27 names as the shape the literal mirrors.
 * A text node that ENDS in one, immediately before a code span, has to be
 * written escaped or it stops being text.
 *
 * The escape has to be applied in the pass that writes it, not left to the
 * document-wide fallback. The minimal/conservative decision is per DOCUMENT:
 * written bare in the minimal pass the sigil binds, the minimal render
 * re-parses differently from the conservative one, and the WHOLE document
 * escalates to conservative - which then escapes every candidate in it,
 * including characters that needed nothing. A paragraph that round-trips bare
 * on its own came back as `foo \(bar\) #baz 50\% a\-b` because of a `!` in an
 * unrelated paragraph below it (markup-carve/carve-php#1412). That is the
 * over-escaping PART 11 §4 forbids. carve-rs is the engine this converges on.
 *
 * The two sigils reach the seam differently, which is why both are here:
 *
 * - `!` from a PARSE. An unclosed backtick run is written back CLOSED, so the
 *   adjacency the source never had appears in the output.
 * - `$` from an INGESTED tree (PART 12). It sat in neither escape class, so
 *   both passes agreed on a document that read back as MATH - not
 *   over-escaping but `toHtml(fmt(x)) == toHtml(x)` broken outright.
 */
class VerbatimSigilEscapeIsStructuralTest extends TestCase
{
    /**
     * @var string
     */
    private const PROSE = 'foo (bar) #baz 50% a-b';

    private function carve(string $source): string
    {
        return trim(CarveConverter::toCarve($source));
    }

    /**
     * The reported document: a `!` reaching across a blank line into an
     * unrelated paragraph.
     */
    public function testABangBeforeACodeSpanDoesNotEscalateTheDocument(): void
    {
        $formatted = $this->carve(self::PROSE . "\n\n!`l\n");

        // The prose keeps the form it has when it stands alone.
        $this->assertStringContainsString(self::PROSE, $formatted);
        // The unclosed literal is written in its canonical closed form.
        $this->assertStringContainsString('!`l`', $formatted);
    }

    public function testTheProseRoundTripsBareOnItsOwn(): void
    {
        // The control the escalation is measured against: if this line needed
        // escaping anyway, the test above would prove nothing.
        $this->assertSame(self::PROSE, $this->carve(self::PROSE . "\n"));
    }

    /**
     * Shapes that need NO escape, asserted BYTE FOR BYTE.
     *
     * Checking only that the prose survived would pass on a guard that escapes
     * the sigil everywhere, or on one that escapes it at any offset rather than
     * only where it abuts the run - both of which are the over-escaping this
     * change exists to remove, one construct smaller.
     *
     * @return array<string, array{string}>
     */
    public static function unescalatedProvider(): array
    {
        return [
            // A CLOSED literal is a `literal_inline` node, so the writer emits
            // the construct and no text sigil is involved at all.
            'closed literal' => ['!`p`'],
            'closed literal with attributes' => ['!`p`{.k}'],
            // A sigil that does not abut a run keeps its bare form.
            'bang before ordinary text' => ['!x'],
            'dollar before ordinary text' => ['$x'],
            'bang at the end of a paragraph' => ['x!'],
            // MID-NODE, with a code span after the node. The sigil is followed
            // by this node's own text, so nothing binds and nothing is escaped
            // - the case a guard that reads only the next sibling gets wrong.
            'bang mid-node before a code span' => ['a!b`x`'],
            'dollar mid-node before a code span' => ['a$b`x`'],
            // An EMPTY code span writes as a bare pair of backticks, which a
            // sigil does NOT bind to - `!``` parses as a text `!` beside an
            // empty code node - so escaping there would add a `\!` PART 11 §2
            // forbids. The exception is real, not defensive.
            'bang before an empty code span' => ['!``'],
            'dollar before an empty code span' => ['$``'],
            // Math and a code span written as themselves.
            'inline math' => ['$`x`'],
            'code span alone' => ['`l`'],
        ];
    }

    #[DataProvider('unescalatedProvider')]
    public function testTheseShapesAreWrittenExactlyAsAuthored(string $tail): void
    {
        $source = self::PROSE . "\n\n" . $tail . "\n";

        $this->assertSame(self::PROSE . "\n\n" . $tail, $this->carve($source));
    }

    /**
     * `fmt` is idempotent and preserves the rendering, on the reported shape.
     *
     * The escape has to be the RIGHT one, not merely present: a spelling that
     * suppressed the escalation while changing what the document says would
     * pass the assertions above.
     */
    public function testTheReportedShapeRoundTrips(): void
    {
        $source = self::PROSE . "\n\n!`l\n";
        $converter = new CarveConverter();

        $once = CarveConverter::toCarve($source);
        $this->assertSame($once, CarveConverter::toCarve($once), 'fmt is not idempotent');
        $this->assertSame($converter->convert($source), $converter->convert($once));
    }

    /**
     * An INGESTED text node ending in a sigil, beside a code span.
     *
     * The parser never builds this tree for `$` - it reads the pair as math -
     * but PART 12 ingest does, and the writer has to hold the invariant for any
     * tree it is handed. Before the guard this wrote `a $` plus a backtick run
     * and read back as math, so the paragraph's own text became a formula.
     *
     * A RAW INLINE is written through the same code-span writer, with a
     * `{=format}` suffix, so it opens with a backtick run too. Reading only the
     * code span left that one corrupting: `a $` beside a raw inline came back
     * as math holding the format block.
     *
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function ingestedSigilProvider(): array
    {
        $code = ['type' => 'code', 'value' => 'x'];
        $raw = ['type' => 'raw_inline', 'content' => 'x', 'format' => 'html'];

        return [
            'dollar before a code span' => ['a $', '<p>a $<code>x</code></p>', $code],
            'bang before a code span' => ['a !', '<p>a !<code>x</code></p>', $code],
            // Only the LAST sigil abuts the run; the one before it is followed
            // by a backslash and stays text.
            'doubled dollar' => ['a $$', '<p>a $$<code>x</code></p>', $code],
            'dollar before a raw inline' => ['a $', '<p>a $x</p>', $raw],
            'bang before a raw inline' => ['a !', '<p>a !x</p>', $raw],
        ];
    }

    /**
     * @param string $text The text node's content, ending in the sigil.
     * @param string $expectedHtml The rendering the tree itself produces.
     * @param array<string, string> $sibling The node written right after it.
     */
    #[DataProvider('ingestedSigilProvider')]
    public function testAnIngestedSigilBesideAVerbatimRunKeepsItsMeaning(
        string $text,
        string $expectedHtml,
        array $sibling,
    ): void {
        $codec = new AstCodec();
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => $text],
                        $sibling,
                    ],
                ],
            ],
        ];

        $written = CarveConverter::carve()->render($codec->decode($payload));
        $htmlOfTree = (new CarveConverter())->render($codec->decode($payload));
        $htmlOfWritten = (new CarveConverter())->convert($written);

        $this->assertSame($expectedHtml, trim($htmlOfTree));
        $this->assertSame(
            $htmlOfTree,
            $htmlOfWritten,
            'toHtml(fmt(x)) != toHtml(x) for: ' . var_export($written, true),
        );
    }
}
