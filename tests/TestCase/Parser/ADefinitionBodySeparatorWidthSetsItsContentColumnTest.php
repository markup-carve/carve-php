<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body's separator is any run of spaces, and its width sets the
 * body's content column (markup-carve/carve#1757).
 *
 * `definition_body = ':', definition_separator, ...` with
 * `definition_separator = space, {space}`, so the content column is
 * `1 + separator width`: a one-space separator establishes column 2, a
 * two-space separator column 3 and a four-space separator column 5. A
 * continuation line qualifies by REACHING ITS OWN BODY'S column, which is the
 * rule PART 9 §24 C1 already applies to a footnote body and a list item.
 *
 * The body was the one marker in the language that demanded two spaces where
 * `- item`, `1. item`, `> quote` and `:: term` all take one, and the one that
 * measured its separator against a FIXED width instead of reading its own. So
 * `: x` folded into the `<dt>` as term text and had no reading of its own at
 * all.
 *
 * The pairs below are the spec corpus category
 * `424-a-definition-body-s-separator-width-sets-its-content-column`, which this
 * repo's pinned corpus does not carry yet. THE TWO-SPACE PAIR IS THE CONTROL:
 * a fix that made every separator behave like one space passes every headline
 * case here and misses the point, because the column would be hard-coded to the
 * new value rather than derived from the width. It is asserted separately below
 * so a failure names it.
 */
class ADefinitionBodySeparatorWidthSetsItsContentColumnTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function corpusPairs(): iterable
    {
        yield 'a one-space separator opens a body' => [
            ":: term\n: definition\n",
            "<dl>\n  <dt>term</dt>\n  <dd>definition</dd>\n</dl>\n",
        ];

        yield 'a continuation reaching column 2 folds into the one-space body' => [
            ":: term\n: first\n\n  second\n",
            "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>first</p>\n    <p>second</p>\n  </dd>\n</dl>\n",
        ];

        yield 'a continuation at column 1 does not reach it' => [
            ":: term\n: first\n\n second\n",
            "<dl>\n  <dt>term</dt>\n  <dd>first</dd>\n</dl>\n<p>second</p>\n",
        ];

        yield 'both spellings may appear in one list' => [
            ":: term\n: one\n:  two\n",
            "<dl>\n  <dt>term</dt>\n  <dd>one</dd>\n  <dd>two</dd>\n</dl>\n",
        ];

        yield 'the first-block form works on the narrow width' => [
            ":: term\n: +\nflush block\n",
            "<dl>\n  <dt>term</dt>\n  <dd>flush block</dd>\n</dl>\n",
        ];

        yield 'a colon line below a folding term ends the term' => [
            ":: term\nwrapped on\n: definition\n",
            "<dl>\n  <dt>term\nwrapped on</dt>\n  <dd>definition</dd>\n</dl>\n",
        ];
    }

    #[DataProvider('corpusPairs')]
    public function testTheSeparatorWidthSetsTheBodySColumn(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testTheTwoSpaceControlKeepsItsWiderColumn(): void
    {
        // THE CONTROL, and the only document in the category that proves the
        // column is DERIVED. The same continuation that folds into a one-space
        // body at column 2 sits BELOW a two-space body's column 3, so it leaves
        // the body and re-parses at document level. An engine that answered
        // "column 2" for every separator would pass every pair above.
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>first</dd>\n</dl>\n<p>second</p>\n",
            $this->html(":: term\n:  first\n\n  second\n"),
        );
    }

    public function testAWiderRunPushesTheColumnFurtherOut(): void
    {
        // `:    x` is column 5, so a continuation at 4 does not reach it and one
        // at 5 does. No corpus document uses a run wider than two, and this is
        // where a fixed `1 + 2` would still pass everything above.
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>first</dd>\n</dl>\n<p>second</p>\n",
            $this->html(":: term\n:    first\n\n    second\n"),
        );
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>first</p>\n    <p>second</p>\n  </dd>\n</dl>\n",
            $this->html(":: term\n:    first\n\n     second\n"),
        );
    }

    public function testADefinitionOnAOneSpaceBodyLineIsCollectedFromIt(): void
    {
        // The description MARKER opens item content exactly as a bullet does, so
        // a definition written on that line is the entry's own content and is
        // collected from it (carve-php#891, spec markup-carve/carve#801). Three
        // readers spell that marker - the block parser, the reference prepass's
        // column tracker and its marker strip - and only the first was widened
        // by the rule above. Left behind, the block parser emptied the `<dd>`
        // while nothing registered the definition, so the reference it feeds
        // stayed literal: the define-nothing family markup-carve/carve#624
        // forbids.
        $this->assertSame(
            $this->html(":: term\n:  [r]: /u\n\nsee [t][r]\n"),
            $this->html(":: term\n: [r]: /u\n\nsee [t][r]\n"),
        );
        $this->assertStringContainsString(
            '<a href="/u">t</a>',
            $this->html(":: term\n: [r]: /u\n\nsee [t][r]\n"),
        );
    }

    public function testADefinitionAtTheOneSpaceBodySOwnColumnIsCollectedFromIt(): void
    {
        // The THIRD reader, and the one the marker-line case above cannot see:
        // a definition written on a CONTINUATION line is collected only when it
        // sits AT the body's content column, so the column tracker has to open
        // one for the narrow spelling too. Left at the two-slot marker it opened
        // no column at all, the definition landed at a column no container
        // claimed, and the reference below it stayed literal.
        $this->assertStringContainsString(
            '<a href="/u">t</a>',
            $this->html(":: term\n: intro\n\n  [r]: /u\n\nsee [t][r]\n"),
        );
    }

    public function testAFootnoteOnAOneSpaceBodyLineIsCollectedFromIt(): void
    {
        $this->assertSame(
            $this->html(":: term\n:  [^f]: x\n\nsee[^f]\n"),
            $this->html(":: term\n: [^f]: x\n\nsee[^f]\n"),
        );
        $this->assertStringContainsString('id="fnref1"', $this->html(":: term\n: [^f]: x\n\nsee[^f]\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function separatorOnlyLines(): iterable
    {
        yield 'one space' => [":: term\n: \n"];
        yield 'two spaces' => [":: term\n:  \n"];
        yield 'three spaces' => [":: term\n:   \n"];
        yield 'no separator at all' => [":: term\n:\n"];
    }

    /**
     * PART 9's MARKER REQUIRES CONTENT holds for `:` at EVERY separator width.
     *
     * The widths have to agree, and they did not: the capturing pattern's
     * separator run is greedy, so without a guard on the content it gives up
     * its last space and a separator-only line opens an empty body at some
     * widths and not others. The prefix that breaks a term's fold has to carry
     * the same guard or the line falls out of the loop as a stray paragraph -
     * the carve#755 shape, which is exactly what a bare two-space separator
     * did before this change.
     */
    #[DataProvider('separatorOnlyLines')]
    public function testASeparatorOnlyLineOpensNoBodyAtAnyWidth(string $source): void
    {
        $this->assertStringNotContainsString('<dd>', $this->html($source));
        $this->assertStringNotContainsString('<p>', $this->html($source));
        $this->assertSame($this->html(":: term\n:\n"), $this->html($source));
    }

    public function testADescriptionLineStillNeedsATermAboveIt(): void
    {
        // Corpus `216-a-description-line-needs-a-term-above-it`, re-asked at the
        // narrow width: widening the marker must not make a bare `: ` line a
        // description anywhere. Without a term above it the line is paragraph
        // text and the definition-shaped content in it defines nothing.
        $html = $this->html(": [r]: /u\n\nsee [t][r]\n");
        $this->assertStringNotContainsString('<dd>', $html);
        $this->assertStringNotContainsString('<a href="/u">', $html);
    }
}
