<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition description whose body holds no blocks is written `: {empty}`,
 * the sentinel PART 11 §7b already uses for an empty footnote definition
 * (markup-carve/carve#1827).
 *
 * The line is a block-attribute line: the block it would attach to does not
 * exist, so the parse consumes it and the description reads back holding
 * nothing. That makes it a fixed point in EVERY position - above a blank line,
 * above a flush-left paragraph, and at end of input - so the writer needs no
 * lookahead over what follows. `: +` renders an empty `<dd>` too, but a `+`
 * ATTACHES the column-0 block under it and is only empty with a blank line
 * after it.
 *
 * Because every entry writes its own description line, consecutive `::` lines
 * never end up sharing one: a `<dl>` writes back as ONE list with the grouping
 * it came in with, and the HTML importer owes no row for an empty `<dd>`.
 */
class AnEmptyDescriptionBodyIsWrittenWithTheSentinelTest extends TestCase
{
    /**
     * @var string
     */
    protected const NOT_LAST = '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>';

    /**
     * @var string
     */
    protected const LAST = '<dl><dt>t1</dt><dd>d1</dd><dt>t2</dt><dd></dd></dl>';

    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render(CarveConverter::create()->parse($source));
    }

    protected function html(string $source): string
    {
        return rtrim(CarveConverter::create()->convert($source), "\n");
    }

    public function testWritesTheSentinelForADescriptionHoldingNoBlocks(): void
    {
        $this->assertSame(":: t\n: {empty}\n", $this->fmt(":: t\n: {empty}\n"));
    }

    public function testTheSentinelRendersAnEmptyDescription(): void
    {
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>", $this->html(":: t\n: {empty}\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixedPointProvider(): array
    {
        return [
            'at end of input' => [":: t\n: {empty}\n"],
            'above a blank line' => [":: t\n: {empty}\n\nflush\n"],
        ];
    }

    #[DataProvider('fixedPointProvider')]
    public function testTheSentinelIsAFixedPoint(string $source): void
    {
        $this->assertSame($source, $this->fmt($source));
    }

    /**
     * The writer separates blocks with a blank line, so the flush-left spelling
     * is not a fixed point - but the sentinel is empty either way, so the
     * rendering does not move.
     *
     * @return array<string, array{0: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'at end of input' => [":: t\n: {empty}\n"],
            'above a blank line' => [":: t\n: {empty}\n\nflush\n"],
            'above a flush-left paragraph' => [":: t\n: {empty}\nflush\n"],
            'the plus spelling' => [":: t\n:  +\n flush\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testTheRenderingDoesNotMoveAcrossARoundTrip(string $source): void
    {
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    /**
     * NO LOOKAHEAD. A flush-left paragraph directly under the sentinel does not
     * attach to it, which is what disqualified `: +`.
     */
    public function testAFlushLeftParagraphStaysOutsideTheDescription(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>flush</p>",
            $this->html(":: t\n: {empty}\nflush\n"),
        );
    }

    /**
     * ONE LIST, FOUR CHILDREN, wherever the empty entry sits.
     */
    public function testAListWhoseEmptyEntryIsNotTheLastOneStaysWhole(): void
    {
        $written = (new HtmlToCarve())->convert(self::NOT_LAST);
        $this->assertSame(":: t1\n: {empty}\n:: t2\n: d2\n", $written);
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dd></dd>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>",
            $this->html($written),
        );
    }

    public function testAListWhoseEmptyEntryIsTheLastOneStaysWhole(): void
    {
        $written = (new HtmlToCarve())->convert(self::LAST);
        $this->assertSame(":: t1\n: d1\n:: t2\n: {empty}\n", $written);
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dd>d1</dd>\n  <dt>t2</dt>\n  <dd></dd>\n</dl>",
            $this->html($written),
        );
    }

    public function testAnEmptyDescriptionDeclaresNoLoss(): void
    {
        foreach ([self::NOT_LAST, self::LAST] as $html) {
            $codes = array_map(
                static fn ($diagnostic): string => $diagnostic->code,
                (new HtmlToCarve())->convertWithReport($html)->diagnostics,
            );
            $this->assertNotContains('structure-unspellable', $codes, $html);
            $this->assertNotContains('structure-split', $codes, $html);
        }
    }

    /**
     * THE CONDITION IS "THIS ENTRY WRITES NOTHING", not "the description is
     * empty": a `<dd>` holding a paragraph of layout whitespace and one holding
     * a list with no items write nothing too, and take the sentinel alike.
     *
     * @return array<string, array{0: string}>
     */
    public static function writesNothingProvider(): array
    {
        return [
            'no children' => ['<dl><dt>t</dt><dd></dd></dl>'],
            'a layout-only paragraph' => ['<dl><dt>t</dt><dd><p> </p></dd></dl>'],
            'a list with no items' => ['<dl><dt>t</dt><dd><ul></ul></dd></dl>'],
        ];
    }

    #[DataProvider('writesNothingProvider')]
    public function testEveryDescriptionThatWritesNothingTakesTheSentinel(string $html): void
    {
        $this->assertSame(":: t\n: {empty}\n", (new HtmlToCarve())->convert($html));
    }

    /**
     * THE AST EXIT AND THE SOURCE EXIT AGREE, with no stand-in in between: the
     * tree read back from the written source is the tree the document has.
     */
    public function testTheAstExitKeepsTheEmptyDescription(): void
    {
        $ast = (new HtmlToCarve())->convertToAst(self::NOT_LAST);
        $items = $ast['children'][0]['items'];
        $this->assertCount(4, $items);
        $this->assertSame('definition_description', $items[1]['type']);
        $this->assertSame([], $items[1]['children']);
    }

    /**
     * THE SENTINEL DOES NOT EAT CONTENT. It is a sentinel only where it is the
     * whole line and reads as a block-attribute line.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function contentProvider(): array
    {
        return [
            'escaped' => [":: t\n: \\{empty}\n", "<dl>\n  <dt>t</dt>\n  <dd>{empty}</dd>\n</dl>"],
            'with text beside it' => [":: t\n: {empty} x\n", "<dl>\n  <dt>t</dt>\n  <dd>{empty} x</dd>\n</dl>"],
        ];
    }

    #[DataProvider('contentProvider')]
    public function testAnEscapedOrAccompaniedBraceRunStaysContent(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
        $this->assertSame($source, $this->fmt($source));
    }
}
