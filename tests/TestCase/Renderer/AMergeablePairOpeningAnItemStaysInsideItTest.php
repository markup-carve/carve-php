<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tight item whose FIRST two children merge with each other.
 *
 * `+` CONTINUES the marker line; it is not a block of its own. Written as the
 * first child's separator it stood alone, became the item's whole content, and
 * both halves of the pair were written at column 0 - so they left the item
 * entirely on re-parse (carve-php#1950).
 *
 * AdjacentAttachedBlockOpenersTest covers the same four kinds with a paragraph
 * ABOVE them, where the marker line already carries content and the existing
 * handling is correct. That is why it stayed green through the defect, and it
 * is what these cases are the missing half of.
 */
final class AMergeablePairOpeningAnItemStaysInsideItTest extends TestCase
{
    /**
     * The four kinds adjacentBlocksMerge() joins, in each of the four hosts an
     * item can stand in. A kind is exercised twice over so the pair is the only
     * thing in the item: with any block above it the marker line is occupied
     * and the defect cannot arise.
     *
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        yield 'blockquote, bullet item' => ["- > d\n\n  > q\n\na\n"];
        yield 'blockquote, ordered item' => ["1. > d\n\n   > q\n\na\n"];
        yield 'blockquote, item in a blockquote' => ["> - > d\n>\n>   > q\n\na\n"];
        yield 'blockquote, item in a div' => ["::: outer\n- > d\n\n  > q\n:::\n\na\n"];

        yield 'table, bullet item' => ["- | a |\n  |---|\n  | b |\n\n  | c |\n  |---|\n  | d |\n\na\n"];
        yield 'table, ordered item' => ["1. | a |\n   |---|\n   | b |\n\n   | c |\n   |---|\n   | d |\n\na\n"];
        yield 'table, item in a blockquote' => ["> - | a |\n>   |---|\n>   | b |\n>\n>   | c |\n>   |---|\n>   | d |\n\na\n"];
        yield 'table, item in a div' => ["::: outer\n- | a |\n  |---|\n  | b |\n\n  | c |\n  |---|\n  | d |\n:::\n\na\n"];

        yield 'line block, bullet item' => ["- ::: |\n  v\n  :::\n\n  ::: |\n  w\n  :::\n\na\n"];
        yield 'line block, ordered item' => ["1. ::: |\n   v\n   :::\n\n   ::: |\n   w\n   :::\n\na\n"];
        yield 'line block, item in a blockquote' => ["> - ::: |\n>   v\n>   :::\n>\n>   ::: |\n>   w\n>   :::\n\na\n"];
        yield 'line block, item in a div' => ["::: outer\n- ::: |\n  v\n  :::\n\n  ::: |\n  w\n  :::\n:::\n\na\n"];

        yield 'definition list, bullet item' => ["- :: t\n  :  d\n\n  :: u\n  :  e\n\na\n"];
        yield 'definition list, ordered item' => ["1. :: t\n   :  d\n\n   :: u\n   :  e\n\na\n"];
        yield 'definition list, item in a blockquote' => ["> - :: t\n>   :  d\n>\n>   :: u\n>   :  e\n\na\n"];
        yield 'definition list, item in a div' => ["::: outer\n- :: t\n  :  d\n\n  :: u\n  :  e\n:::\n\na\n"];
    }

    /**
     * Split from the fixpoint case below rather than collapsed with it: a pass
     * can reach a fixpoint on a document it has already broken, so agreeing
     * output is not evidence the item survived.
     */
    #[DataProvider('cases')]
    public function testTheWriterPassKeepsTheHtml(string $source): void
    {
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($converter->toCarve($source)));
    }

    #[DataProvider('cases')]
    public function testTheWrittenSourceIsAFixpoint(string $source): void
    {
        $converter = new CarveConverter();
        $once = $converter->toCarve($source);
        self::assertSame($once, $converter->toCarve($once));
    }

    /**
     * The canonical spelling, pinned in bytes on the one case the ticket names.
     * The first half stays on the marker line at the content column and the `+`
     * parts the second - which is the same shape a paragraph above the pair
     * already produced, minus the paragraph.
     */
    public function testTheFirstHalfStaysOnTheMarkerLine(): void
    {
        self::assertSame("- > d\n+\n> q\n\na\n", (new CarveConverter())->toCarve("- > d\n\n  > q\n\na\n"));
    }

    /**
     * The lookahead this narrows is still what parts a pair standing BELOW
     * other content, so the run form is unchanged.
     */
    public function testAPairBelowAParagraphKeepsTheRunForm(): void
    {
        self::assertSame("- x\n+\n> q\n+\n> r\n", (new CarveConverter())->toCarve("- x\n+\n> q\n+\n> r\n"));
    }

    /**
     * An isolated opener has no pair to part, so it keeps the indented form -
     * the lookbehind must not manufacture a marker column for it.
     */
    public function testAnIsolatedOpenerKeepsTheIndentedForm(): void
    {
        self::assertSame("- x\n  > q\n", (new CarveConverter())->toCarve("- x\n+\n> q\n"));
    }
}
