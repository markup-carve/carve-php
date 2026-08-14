<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block-level HTML element is a BLOCK inside a container too.
 *
 * CommonMark's HTML block start conditions apply inside a block quote or a list
 * item exactly as they do at document level, and conditions 1 to 6 may
 * interrupt an open paragraph. The importer had no notion of them, so the
 * element folded into the paragraph above it and ended up inside a `<p>` that
 * takes phrasing content only. A second rule compounded it: the indented-code
 * test measured from column 0 rather than from the enclosing item's content
 * column, so a nested item's own content was read as code and re-emitted as a
 * fence at column 0, carrying it out of the list.
 *
 * The readings below were taken with `commonmark` 0.31.2 and `marked` 18.0.9,
 * installed outside the checkout so nothing is added to the package's own
 * dependencies. The two agree on every shape asserted here except the three
 * noted at testEveryInterruptingStartConditionOpensABlock(), where `marked`
 * does not interrupt on conditions 3, 4 and 5 at document level; the spec says
 * conditions 1 to 6 all may, so `commonmark` decides those.
 */
class MarkdownRawHtmlBlockInContainersTest extends TestCase
{
    /**
     * The nested item's content column, which is the whole subject of the
     * indented-code half. Built from escapes and pinned by
     * testTheColumnFixturesStillCarryTheirSignificantSpaces() so a formatter
     * cannot quietly rewrite the columns and leave the assertions testing
     * nothing.
     *
     * @var string
     */
    private const COLUMN_4 = "\x20\x20\x20\x20";

    /**
     * @var string
     */
    private const COLUMN_2 = "\x20\x20";

    /**
     * A literal tab, which advances to the next four-column stop rather than
     * counting as one column. A formatter rewriting this into spaces would make
     * every tab assertion below pass while testing nothing at all.
     *
     * @var string
     */
    private const TAB = "\x09";

    private MarkdownToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new MarkdownToCarve();
    }

    public function testTheColumnFixturesStillCarryTheirSignificantSpaces(): void
    {
        // A formatter rewrote a literal tab into spaces in a fixture here once
        // and the test kept passing while testing nothing. Column placement is
        // the defect, so the bytes are pinned rather than trusted.
        $this->assertSame('20202020', bin2hex(self::COLUMN_4));
        $this->assertSame('2020', bin2hex(self::COLUMN_2));
        $this->assertSame('09', bin2hex(self::TAB));
    }

    public function testAnOpenBlockEndsWhereItsContainerDoes(): void
    {
        // A block belongs to the container it opened in, so a line that leaves
        // that container ends it however far the block would otherwise have
        // run. Without this the dedented element stayed attached to the quote
        // it had already left.
        $this->assertSame(
            "> <div>\n> x\n\n<footer>y</footer>\n",
            $this->converter->convert("> <div>\n> x\n<footer>y</footer>\n"),
        );
        $this->assertSame(
            "- <div>\n" . self::COLUMN_2 . "x\n\n<footer>y</footer>\n",
            $this->converter->convert("- <div>\n" . self::COLUMN_2 . "x\n<footer>y</footer>\n"),
        );
    }

    public function testTabIndentedContentIsMeasuredAndDedentedInColumns(): void
    {
        // A tab advances to the next four-column stop. One tab inside a
        // two-column item reaches column 4, which is two past the content
        // column - the item's own content, not code.
        $this->assertSame(
            "- item\n\n" . self::TAB . "code\n",
            $this->converter->convert("- item\n\n" . self::TAB . "code\n"),
        );

        // Two tabs reach column 8, four past it, so this is code - dedented by
        // the container's columns plus the one step, not by one literal tab.
        $this->assertSame(
            "- item\n\n" . self::COLUMN_2 . "```\n"
                . self::COLUMN_4 . "indented code\n" . self::COLUMN_2 . "```\n",
            $this->converter->convert("- item\n\n" . self::TAB . self::TAB . "indented code\n"),
        );

        // Mixed spaces and tabs reach column 6, exactly four past it.
        $this->assertSame(
            "- item\n\n" . self::COLUMN_2 . "```\n"
                . self::COLUMN_2 . "code\n" . self::COLUMN_2 . "```\n",
            $this->converter->convert("- item\n\n" . self::COLUMN_2 . self::TAB . self::COLUMN_2 . "code\n"),
        );

        // At document level one tab is the whole indent step.
        $this->assertSame(
            "prose\n\n```\ncode\n```\n",
            $this->converter->convert("prose\n\n" . self::TAB . "code\n"),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function containerProvider(): array
    {
        return [
            'document level' => [
                "prose line\n<footer>x</footer>\n",
                "prose line\n\n<footer>x</footer>\n",
            ],
            'block quote' => [
                "> prose line\n> <footer>x</footer>\n",
                "> prose line\n>\n> <footer>x</footer>\n",
            ],
            'nested block quote' => [
                "> > prose line\n> > <footer>x</footer>\n",
                "> > prose line\n> >\n> > <footer>x</footer>\n",
            ],
            'block quote indented one column' => [
                " > prose line\n > <footer>x</footer>\n",
                "> prose line\n>\n> <footer>x</footer>\n",
            ],
            'bullet item' => [
                "- prose line\n  <footer>x</footer>\n",
                "- prose line\n\n  <footer>x</footer>\n",
            ],
            'ordered item' => [
                "1. prose line\n   <footer>x</footer>\n",
                "1. prose line\n\n   <footer>x</footer>\n",
            ],
            'nested bullet item' => [
                "  - prose line\n    <footer>x</footer>\n",
                "  - prose line\n\n    <footer>x</footer>\n",
            ],
            'item inside a quote' => [
                "> - prose line\n>   <footer>x</footer>\n",
                "> - prose line\n>\n>   <footer>x</footer>\n",
            ],
            'quote inside an item' => [
                "- > prose line\n  > <footer>x</footer>\n",
                "- > prose line\n  >\n  > <footer>x</footer>\n",
            ],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testABlockOpenerInterruptsAnOpenParagraphInEveryContainer(
        string $markdown,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function startConditionProvider(): array
    {
        return [
            // Conditions 1 to 6. `commonmark` opens a block on every one of
            // them after prose; `marked` agrees except on `<?`, `<!LETTER` and
            // `<![CDATA[` at document level, where it keeps the line inline.
            'condition 1, script' => ['<script>var a = 1;</script>', true],
            'condition 1, pre' => ['<pre>x</pre>', true],
            'condition 2, comment' => ['<!-- a note -->', true],
            'condition 3, processing instruction' => ['<?php echo 1; ?>', true],
            'condition 4, declaration' => ['<!DOCTYPE html>', true],
            'condition 5, CDATA' => ['<![CDATA[raw]]>', true],
            'condition 6, open tag' => ['<footer>Socrates</footer>', true],
            'condition 6, closing tag' => ['</div>', true],
            'condition 6, self-closing tag' => ['<hr/>', true],
            // Condition 7 is the one that cannot interrupt a paragraph, and it
            // is what keeps an inline element inline.
            'condition 7, span' => ['<span>only this line</span>', false],
            'condition 7, closing span' => ['</span>', false],
            // `source` was dropped from the condition-6 tag list, so it is a
            // condition-7 tag and does not interrupt either.
            'condition 7, source' => ['<source>', false],
            'not a tag at all' => ['<not a tag>', false],
            'an autolink' => ['<https://example.com>', false],
            'an email autolink' => ['<x@example.com>', false],
        ];
    }

    #[DataProvider('startConditionProvider')]
    public function testEveryInterruptingStartConditionOpensABlock(string $opener, bool $interrupts): void
    {
        $expected = $interrupts
            ? "prose line\n\n" . $opener . "\n"
            : "prose line\n" . $opener . "\n";
        $this->assertSame($expected, $this->converter->convert("prose line\n" . $opener . "\n"));

        $quoted = $interrupts
            ? "> prose line\n>\n> " . $opener . "\n"
            : "> prose line\n> " . $opener . "\n";
        $this->assertSame($quoted, $this->converter->convert("> prose line\n> " . $opener . "\n"));
    }

    public function testAnOpenerFourColumnsPastTheContentColumnIsNotABlock(): void
    {
        // Four columns past the container's content column is indented-code
        // territory, and indented code interrupts nothing - the line is lazy
        // paragraph continuation, which is what both readers report.
        $this->assertSame(
            "prose line\n" . self::COLUMN_4 . "<footer>x</footer>\n",
            $this->converter->convert("prose line\n" . self::COLUMN_4 . "<footer>x</footer>\n"),
        );
        $this->assertSame(
            "- prose line\n" . self::COLUMN_4 . self::COLUMN_2 . "<footer>x</footer>\n",
            $this->converter->convert("- prose line\n" . self::COLUMN_4 . self::COLUMN_2 . "<footer>x</footer>\n"),
        );

        // Three columns past it still opens a block, in both places.
        $this->assertSame(
            "prose line\n\n   <footer>x</footer>\n",
            $this->converter->convert("prose line\n   <footer>x</footer>\n"),
        );
        $this->assertSame(
            "- prose line\n\n     <footer>x</footer>\n",
            $this->converter->convert("- prose line\n     <footer>x</footer>\n"),
        );
    }

    public function testANestedItemsOwnContentColumnIsNotIndentedCode(): void
    {
        // The defect this covers: the element was re-emitted as a fence at
        // column 0, which put it below the whole list and changed its kind.
        $markdown = "- outer\n" . self::COLUMN_2 . "- inner\n\n" . self::COLUMN_4 . "<footer>x</footer>\n";
        $out = $this->converter->convert($markdown);

        $this->assertSame(
            "- outer\n" . self::COLUMN_2 . "- inner\n\n" . self::COLUMN_4 . "<footer>x</footer>\n",
            $out,
        );
        $this->assertStringNotContainsString('```', $out);

        // Placement is the defect, so order and column are asserted rather than
        // containment: the element must come AFTER the item that holds it, and
        // stand at that item's content column.
        $lines = explode("\n", $out);
        $inner = array_search(self::COLUMN_2 . '- inner', $lines, true);
        $element = array_search(self::COLUMN_4 . '<footer>x</footer>', $lines, true);
        $this->assertIsInt($inner);
        $this->assertIsInt($element);
        $this->assertGreaterThan($inner, $element);
    }

    public function testAnItemsOwnContentColumnIsNotIndentedCodeAtAnyDepth(): void
    {
        $this->assertSame(
            "- item\n\n" . self::COLUMN_4 . "more prose\n",
            $this->converter->convert("- item\n\n" . self::COLUMN_4 . "more prose\n"),
        );
        $this->assertSame(
            "1. item\n\n     more prose\n",
            $this->converter->convert("1. item\n\n     more prose\n"),
        );
        $this->assertSame(
            "- a\n" . self::COLUMN_2 . "- b\n" . self::COLUMN_4 . "- c\n\n      <footer>x</footer>\n",
            $this->converter->convert(
                "- a\n" . self::COLUMN_2 . "- b\n" . self::COLUMN_4 . "- c\n\n      <footer>x</footer>\n",
            ),
        );
    }

    public function testIndentedCodeInsideAnItemKeepsItsFenceInTheItem(): void
    {
        // Genuine indented code - four columns past the item's content column -
        // still becomes a fence, but at the item's column, not at 0.
        $this->assertSame(
            "- item\n\n" . self::COLUMN_2 . "```\n" . self::COLUMN_2 . "indented code\n" . self::COLUMN_2 . "```\n",
            $this->converter->convert("- item\n\n      indented code\n"),
        );
        $this->assertSame(
            "- outer\n" . self::COLUMN_2 . "- inner\n\n"
                . self::COLUMN_4 . "```\n" . self::COLUMN_4 . "indented code\n" . self::COLUMN_4 . "```\n",
            $this->converter->convert("- outer\n" . self::COLUMN_2 . "- inner\n\n        indented code\n"),
        );
    }

    public function testIndentedCodeAtDocumentLevelStillFencesAtColumnZero(): void
    {
        $this->assertSame(
            "prose\n\n```\nindented code\n```\n",
            $this->converter->convert("prose\n\n" . self::COLUMN_4 . "indented code\n"),
        );
    }

    public function testAConditionOneToFiveBlockEndsAtItsOwnTerminator(): void
    {
        // Conditions 1 to 5 close on their terminator, so a line below them is
        // a new block even with no blank line between.
        $this->assertSame(
            "<script>var a = 1;</script>\n\nafter\n",
            $this->converter->convert("<script>var a = 1;</script>\nafter\n"),
        );
        $this->assertSame(
            "<!-- a note -->\n\nafter\n",
            $this->converter->convert("<!-- a note -->\nafter\n"),
        );
        $this->assertSame(
            "<!--\nnote\n-->\n\nafter\n",
            $this->converter->convert("<!--\nnote\n-->\nafter\n"),
        );
        $this->assertSame(
            "> <!-- a note -->\n>\n> after\n",
            $this->converter->convert("> <!-- a note -->\n> after\n"),
        );
    }

    public function testAMultiLineElementIsNotSplitByItsOwnClosingTag(): void
    {
        // A condition-6 block runs to the next blank line, so nothing inside it
        // opens another block - the closing tag is part of the element, not a
        // new one. Without this the importer inserted a break before `</div>`.
        $this->assertSame(
            "<div>\na\n</div>\nafter\n",
            $this->converter->convert("<div>\na\n</div>\nafter\n"),
        );
        $this->assertSame(
            "> quoted\n>\n> <div>\n> line two\n> </div>\n",
            $this->converter->convert("> quoted\n> <div>\n> line two\n> </div>\n"),
        );
    }

    public function testABlockOpenerEndsALazyContinuation(): void
    {
        // An opener is not a lazy continuation line, so the container closes and
        // the element is its sibling, not its child.
        $this->assertSame(
            "- prose line\n\n<footer>x</footer>\n",
            $this->converter->convert("- prose line\n<footer>x</footer>\n"),
        );
        $this->assertSame(
            "> prose line\n\n<footer>x</footer>\n",
            $this->converter->convert("> prose line\n<footer>x</footer>\n"),
        );
    }

    public function testAnElementAlreadyOnItsOwnBlockIsLeftAlone(): void
    {
        // No separator is added where the source already carries one. An
        // authored blank quote line comes back as `>` plus the space the marker
        // normalization has always written, which is why those two expectations
        // differ from their input by that one byte.
        $pairs = [
            ["> quoted\n>\n> <footer>x</footer>\n", "> quoted\n>\x20\n> <footer>x</footer>\n"],
            ["> - item\n>\n>   <footer>x</footer>\n", "> - item\n>\x20\n>   <footer>x</footer>\n"],
        ];
        foreach ($pairs as [$markdown, $expected]) {
            $this->assertSame($expected, $this->converter->convert($markdown));
        }

        foreach (
            [
                "- item\n\n" . self::COLUMN_2 . "<footer>x</footer>\n",
                "1. item\n\n   <footer>x</footer>\n",
                "  - item\n\n" . self::COLUMN_4 . "<footer>x</footer>\n",
                "<footer>standalone</footer>\n",
            ] as $carve
        ) {
            $this->assertSame($carve, $this->converter->convert($carve));
        }
    }

    public function testAnInlineElementStaysInlineInEveryContainer(): void
    {
        // The control that proves the rule discriminates rather than blanket
        // -blocking every line that opens with `<`.
        foreach (
            [
                "> quoted <span>inline</span> prose\n",
                "- item with <span>inline</span> text\n",
                "prose line\n<span>only this line</span>\nmore prose\n",
                "prose line\na < b and c > d\n",
            ] as $markdown
        ) {
            $this->assertSame($markdown, $this->converter->convert($markdown));
        }
    }
}
