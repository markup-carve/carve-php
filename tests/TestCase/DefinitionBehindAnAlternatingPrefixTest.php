<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\ContainerPrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition registers at the innermost content column whatever the prefix
 * above it alternates.
 *
 * THE COLUMN IS REACHED BY COMPOSING THE STRIPS, NOT BY WALKING THE PREFIX
 * (grammar PART 1 S4, markup-carve/carve#1368; corpus category 360). Every
 * container strips its own prefix and hands the residue down, so which column a
 * line reaches is a question about the innermost container in the coordinates
 * IT was handed - not about how many quotes and list items the prefix
 * alternates above it.
 *
 * THE CORPUS PINS FOUR DOCUMENTS AND THIS PINS THE PROPERTY. Category 360 holds
 * one link spelling, its footnote kind and two controls; the ruling is stated
 * over all 62 quote/list prefixes to depth five and goes stale at depth six, so
 * a list of prefixes is not what it says. The sweep below is that statement:
 * every prefix, both definition kinds. This engine answered 52 of 62 on links
 * and 44 of 62 on footnotes before the fix, and the ten and eighteen it
 * declined were ones it answered correctly with one container peeled off.
 *
 * THE OPACITY CONTROLS ARE THE OTHER HALF, and they are what a fix written one
 * container too wide fails. Reaching the column is exactly what makes a
 * definition inside a code SAMPLE, a comment or a line block reachable too, and
 * in all three the definition must register NOTHING - so those shapes are
 * asserted at the same prefixes rather than assumed unaffected.
 */
class DefinitionBehindAnAlternatingPrefixTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * Every quote/list prefix to depth five, outermost first.
     *
     * @return array<string, array{0: string}>
     */
    public static function prefixProvider(): array
    {
        $cases = [];
        for ($depth = 1; $depth <= 5; $depth++) {
            for ($bits = 0; $bits < 1 << $depth; $bits++) {
                $prefix = '';
                for ($step = 0; $step < $depth; $step++) {
                    $prefix .= ($bits >> ($depth - 1 - $step) & 1) === 1 ? 'q' : 'l';
                }
                $cases[$prefix] = [$prefix];
            }
        }

        return $cases;
    }

    /**
     * The line that OPENS the containers: `lql` becomes `- > - x`.
     */
    protected static function opener(string $prefix, string $tail): string
    {
        $line = '';
        foreach (str_split($prefix) as $step) {
            $line .= $step === 'l' ? '- ' : '> ';
        }

        return $line . $tail;
    }

    /**
     * A line INSIDE those containers: an item contributes its content column as
     * indentation, a quote contributes its marker, and then the payload.
     *
     * That is the composition the column is reached by.
     */
    protected static function inside(string $prefix, string $payload): string
    {
        $line = '';
        foreach (str_split($prefix) as $step) {
            $line .= $step === 'l' ? '  ' : '> ';
        }

        return $line . $payload;
    }

    #[DataProvider('prefixProvider')]
    public function testALinkDefinitionRegistersAtEveryPrefix(string $prefix): void
    {
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n" . self::inside($prefix, '[r]: /url') . "\n\nSee [r][].\n",
        );

        $this->assertStringContainsString('<a href="/url">r</a>', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    #[DataProvider('prefixProvider')]
    public function testTheFootnoteKindRegistersAtEveryPrefix(string $prefix): void
    {
        // The two kinds are pinned apart because this engine sorted by kind:
        // ten link prefixes failed against eighteen footnote ones, so a fix
        // measured on links alone leaves half the defect standing.
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n" . self::inside($prefix, '[^f]: note') . "\n\nSee [^f].\n",
        );

        $this->assertStringContainsString('role="doc-endnotes"', $html);
        $this->assertStringNotContainsString('[^f]: note', $html);
    }

    #[DataProvider('prefixProvider')]
    public function testADefinitionInACodeSampleRegistersNowhere(string $prefix): void
    {
        // The INTENDED SURVIVOR. A prepass that reaches the column also reaches
        // into a fenced sample written at it, and documenting the syntax must
        // not change the prose around it.
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n"
            . self::inside($prefix, '```') . "\n"
            . self::inside($prefix, '[r]: /url') . "\n"
            . self::inside($prefix, '```') . "\n\nSee [r][].\n",
        );

        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    #[DataProvider('prefixProvider')]
    public function testADefinitionBELOWAClosedSampleStillRegisters(string $prefix): void
    {
        // The control that separates a fence that CLOSED from one that
        // swallowed the rest of the document. Both leave a definition inside
        // the sample unregistered, so the test above cannot tell them apart -
        // and reading the closer in the wrong frame produces the second, which
        // silently drops every definition below it.
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n"
            . self::inside($prefix, '```') . "\n"
            . self::inside($prefix, 'z') . "\n"
            . self::inside($prefix, '```') . "\n"
            . self::inside($prefix, '[r]: /url') . "\n\nSee [r][].\n",
        );

        $this->assertStringContainsString('<a href="/url">r</a>', $html);
    }

    #[DataProvider('prefixProvider')]
    public function testADefinitionInACommentRegistersNowhere(string $prefix): void
    {
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n"
            . self::inside($prefix, '%%%') . "\n"
            . self::inside($prefix, '[r]: /url') . "\n"
            . self::inside($prefix, '%%%') . "\n\nSee [r][].\n",
        );

        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    #[DataProvider('prefixProvider')]
    public function testADefinitionInVerseRegistersNowhere(string $prefix): void
    {
        // A line block's body is inline content, so the block-level definition
        // form cannot occur there: the line renders as verse and defines
        // nothing (PART 9 §23, carve#574).
        $html = $this->converter->convert(
            self::opener($prefix, 'x') . "\n"
            . self::inside($prefix, '::: |') . "\n"
            . self::inside($prefix, '[r]: /url') . "\n"
            . self::inside($prefix, ':::') . "\n\nSee [r][].\n",
        );

        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    public function testTheColumnIsStillExact(): void
    {
        // THE ENGINES DISAGREE ON THIS ONE SHAPE, and this asserted the other
        // side of it until carve-php#1853. carve-js registers the definition
        // here; carve-rs folds the line into the open paragraph and registers
        // nothing. This engine answered BOTH ways - the line pre-pass agreed
        // with carve-js and the structural walk agrees with carve-rs - so which
        // answer a document got depended on whether an unrelated second
        // definition kind appeared elsewhere in it.
        //
        // Retiring the pre-pass settles this engine on carve-rs's answer. The
        // normative question is markup-carve/carve#1896; there is no corpus row
        // for the shape, which is why the divergence went unnoticed. What is
        // asserted here is only that the line STAYS VISIBLE either way, which
        // is the property this test was added for and the one both answers
        // share.
        $html = $this->converter->convert("- > - - x\n  >    [r]: /url\n\nSee [r][].\n");

        $this->assertStringContainsString('[r]: /url', $html);
    }

    public function testIndentationAloneIsStillNotAQuote(): void
    {
        // A budget spent across the prefix must not become a licence to eat
        // arbitrary indentation: at top level the budget is 0, so a four-space
        // `>` is indented text (carve-php#788, tests/BlockquoteRefDefTest).
        $html = $this->converter->convert("    > [r]: /url\n\nSee [r][].\n");

        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    public function testTheColumnIsSpentOnce(): void
    {
        // A column is spent once, and the residue is the line. Under `- x` a
        // four-space continuation keeps two residual columns, so it is the
        // paragraph text §24 C3 says it is - two dedents together put the `[`
        // at position 0 and registered it.
        $html = $this->converter->convert("- x\n    [r]: /url\n\nSee [r][].\n");

        $this->assertStringContainsString('<a href="/url">r</a>', $html);
    }

    public function testAnItemThatENDEDDoesNotClaimTheColumnItHeld(): void
    {
        // A column is a number, and two different container sequences reach the
        // same number. `> - a` opens an item at column 4 INSIDE a quote; four
        // spaces below a blank reach column 4 and are inside nothing, because
        // the quote and its item both ended at the blank. Keeping only the
        // number made that visible top-level line the item's continuation.
        $html = $this->converter->convert("> - a\n\n    [^f]: note\n\nSee [^f].\n");

        $this->assertStringNotContainsString('role="doc-endnotes"', $html);
        $this->assertStringContainsString('[^f]: note', $html);
    }

    public function testAQuoteThatENDEDTakesItsItemWithIt(): void
    {
        // Two spaces below the blank reach column 2, and the item that held
        // column 2 was inside a quote the blank ended. The line is top-level
        // indented text and defines nothing.
        $html = $this->converter->convert("> - a\n\n  [r]: /url\n\nSee [r][].\n");

        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
        $this->assertStringContainsString('[r]: /url', $html);
    }

    public function testADescriptionColumnIsOPENEDBehindAQuoteToo(): void
    {
        // The other direction, and the reason the walk's residue is what the
        // description marker is read on: behind a quote the marker is written
        // `> :  x`, and a reading anchored at column 0 of the raw line opens no
        // column at all - so the definitions written in that `dd` stop being
        // collected while the block parser goes on emptying the entry.
        $reference = $this->converter->convert("> :: term\n> :  x\n>    [r]: /url\n\nSee [r][].\n");
        $footnote = $this->converter->convert("> :: term\n> :  x\n>    [^f]: note\n\nSee [^f].\n");

        $this->assertStringContainsString('<a href="/url">r</a>', $reference);
        $this->assertStringContainsString('role="doc-endnotes"', $footnote);
    }

    public function testAQuotedLineInsideVerseIsStillVerse(): void
    {
        // A line carrying MORE quote markers than the region's opener is
        // content inside it, not its closer. Reading the closer at the fully
        // stripped tail ended the line block on this line, and the definition
        // the page prints as verse registered as well.
        $reference = $this->converter->convert("::: |\n> [r]: /url\n:::\n\nSee [r][].\n");
        $footnote = $this->converter->convert("::: |\n> [^f]: note\n:::\n\nSee [^f].\n");
        // And one quote deeper than an already-quoted block, which is the same
        // question asked at a depth that is not zero.
        $quoted = $this->converter->convert("> ::: |\n> > [r]: /url\n> :::\n\nSee [r][].\n");
        $this->assertStringNotContainsString('<a href="/url">r</a>', $quoted);

        $this->assertStringNotContainsString('role="doc-endnotes"', $footnote);
        $this->assertStringContainsString('[^f]: note', $footnote);
        $this->assertStringNotContainsString('<a href="/url">r</a>', $reference);
        $this->assertStringContainsString('[r]: /url', $reference);
    }

    public function testTheComposedWalkReportsWhereAndHowDeep(): void
    {
        $this->assertSame(
            ['line' => '[r]: /url', 'quoteDepth' => 1],
            ContainerPrefix::composedWalk('  >     [r]: /url', 8),
        );
        $this->assertSame(
            ['line' => '[r]: /url', 'quoteDepth' => 1],
            ContainerPrefix::composedWalk('>     [r]: /url', 6),
        );
        // A quote marker that would spend PAST the column is not on the path to
        // it, so the line reaches nothing.
        $this->assertNull(ContainerPrefix::composedWalk('  > a', 3));
        // No column to reach.
        $this->assertNull(ContainerPrefix::composedWalk('  > a', 0));
    }

    public function testACloserIsReadAtExactlyTheOpenersDepth(): void
    {
        // What a region's closer is read with. EXACTLY the depth in both
        // directions: one marker short and the line has left the quote the
        // region sits in, which ends it; one marker MORE and the line is quoted
        // content inside the region, which does not.
        //
        // No document distinguishes the overshoot case today, because a line
        // that reaches the column through an extra quote marker has also left
        // the item that held the column, and the column tracker pops it first.
        // The rule is the helper's contract either way, so it is pinned here
        // rather than left to a caller that happens not to reach it.
        $this->assertSame(':::', ContainerPrefix::atColumnAndDepth('>   :::', 4, 1));
        $this->assertNull(ContainerPrefix::atColumnAndDepth('> > :::', 4, 1));
        $this->assertSame('> :::', ContainerPrefix::atColumnAndDepth('> > :::', 0, 1));
        $this->assertNull(ContainerPrefix::atColumnAndDepth(':::', 0, 1));
    }

    public function testTheDepthSeparatesTwoCompositionsOfOneColumn(): void
    {
        // Both reach column 4, and only one of them is at depth 1. That is what
        // keeps a nested `>` inside a region from reading as the region's own
        // closer (carve-php#685).
        $this->assertSame(
            ['line' => ':::', 'quoteDepth' => 1],
            ContainerPrefix::composedWalk('>   :::', 4),
        );
        $this->assertSame(
            ['line' => ':::', 'quoteDepth' => 2],
            ContainerPrefix::composedWalk('> > :::', 4),
        );
    }
}
