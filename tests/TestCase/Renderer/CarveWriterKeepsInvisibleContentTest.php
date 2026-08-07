<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Carve writer keeps every character that is not `whitespace`.
 *
 * `whitespace = ' ' | '\t'` (PART 1, markup-carve/carve#890). Every other
 * invisible character is CONTENT: the parser keeps it, the AST carries it, and
 * the HTML renderer emits it - corpus category
 * `a-blank-line-holds-spaces-and-tabs-and-nothing-else` pins all three. The
 * Carve writer was the one artifact deleting it, in two separate places, so
 * `to_html(fmt(x)) == to_html(x)` failed on any document holding one.
 *
 * TWO PLACES, TWO MECHANISMS, and each one alone leaves the other's characters
 * still disappearing - which is why the two providers below are separate:
 *
 * - the per-line TRAILING strip spelled its run `[^\S<NBSP>]+$` under `/u`.
 *   `\S` is the Unicode property there, so the class was "every Unicode space
 *   except NBSP" and it ate U+1680, U+2000, U+2009, U+200A, U+202F, U+205F and
 *   U+2028 off the end of a line. Excluding NBSP BY NAME was the tell: the
 *   class needed an exception carved out of it, and NBSP happened to be the one
 *   member somebody had noticed.
 * - `escapeText()` stripped every C0 control but tab and newline, plus DEL and
 *   the whole C1 block, wherever it sat. That reached U+000B, U+000C and
 *   U+0085 - the three the trailing strip did not.
 *
 * `\r` is the one character still removed, and it is removed for a reason that
 * is not sanitization: `newline = '\n' | '\r\n' | '\r'`, so writing one would
 * end the line and re-parse the rest of the text node as a following block.
 */
class CarveWriterKeepsInvisibleContentTest extends TestCase
{
    /**
     * One character per row, each on a line of its own inside a paragraph.
     *
     * A line of its own is the discriminating position: it puts the character
     * at a line END (where the trailing strip runs) and makes its loss visible
     * as a missing line rather than as a byte nobody can see.
     *
     * @return array<string, array{0: string}>
     */
    public static function invisibleContentProvider(): array
    {
        return [
            'no-break space' => ["\u{00A0}"],
            'ogham space mark' => ["\u{1680}"],
            'en quad' => ["\u{2000}"],
            'thin space' => ["\u{2009}"],
            'hair space' => ["\u{200A}"],
            'zero width space' => ["\u{200B}"],
            'narrow no-break space' => ["\u{202F}"],
            'medium mathematical space' => ["\u{205F}"],
            'ideographic space' => ["\u{3000}"],
            'line separator' => ["\u{2028}"],
            'paragraph separator' => ["\u{2029}"],
            'vertical tab' => ["\u{000B}"],
            'form feed' => ["\u{000C}"],
            'next line' => ["\u{0085}"],
            'byte order mark' => ["\u{FEFF}"],
            'start of heading' => ["\u{0001}"],
            'delete' => ["\u{007F}"],
            'C1 control' => ["\u{0090}"],
        ];
    }

    #[DataProvider('invisibleContentProvider')]
    public function testTheWriterKeepsTheCharacter(string $char): void
    {
        $source = "a\n{$char}\nb\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    #[DataProvider('invisibleContentProvider')]
    public function testFormattingPreservesTheRendering(string $char): void
    {
        // The invariant the writer exists to satisfy. Asserted separately from
        // the byte-level row above because a writer could preserve the byte and
        // still place it somewhere that re-parses differently.
        $source = "a\n{$char}\nb\n";
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert(CarveConverter::toCarve($source)),
        );
    }

    /**
     * A space and a tab still go, and that is the whole of what goes.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function droppedRunProvider(): array
    {
        return [
            'trailing space before a blank line' => ["a  \n\nb\n", "a\n\nb\n"],
            'trailing tab before a blank line' => ["a\t\n\nb\n", "a\n\nb\n"],
            'a space-only line is emitted empty' => ["a\n \nb\n", "a\n\nb\n"],
            'a tab-only line is emitted empty' => ["a\n\t\nb\n", "a\n\nb\n"],
        ];
    }

    #[DataProvider('droppedRunProvider')]
    public function testASpaceOrTabRunIsStillDropped(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    /**
     * A carriage return is still removed, and it is the only one.
     *
     * It reaches the writer only through an ingested tree - a parse normalizes
     * line endings first - so this is asserted through the source that would
     * carry one if it survived.
     */
    public function testACarriageReturnIsStillRemoved(): void
    {
        $this->assertStringNotContainsString("\r", CarveConverter::toCarve("a\r\nb\n"));
    }

    public function testEveryCharacterIsStillCovered(): void
    {
        // A row dropped from the provider would take its character's coverage
        // with it and nothing else here would fail. The two mechanisms had
        // disjoint reach, so both halves have to stay represented: the Unicode
        // spaces the trailing strip ate, and the controls escapeText ate.
        $this->assertCount(18, self::invisibleContentProvider());
        $this->assertCount(4, self::droppedRunProvider());
    }
}
