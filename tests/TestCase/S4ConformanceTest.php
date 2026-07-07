<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * S4 cross-impl conformance: carve-php brought in line with carve-js/carve-rs.
 */
class S4ConformanceTest extends TestCase
{
    private CarveConverter $c;

    protected function setUp(): void
    {
        $this->c = new CarveConverter();
    }

    public function testBlockquoteSpaceAfterMarkerIsOptional(): void
    {
        $this->assertSame("<blockquote><p>tight</p></blockquote>\n", $this->c->convert('>tight'));
        // nested with no spaces
        $this->assertStringContainsString(
            '<blockquote>',
            $this->c->convert('>>>x'),
        );
    }

    public function testClassesAreDeduplicated(): void
    {
        // grammar §15: classes accumulate in source order, deduping repeats
        // (matches carve-js / carve-rs).
        $this->assertSame('<p><span class="a">x</span></p>' . "\n", $this->c->convert('[x]{.a .a}'));
        $this->assertSame('<p><span class="a b">x</span></p>' . "\n", $this->c->convert('[x]{.a .b .a}'));
    }

    public function testBareHashIsNotAHeading(): void
    {
        $this->assertSame("<p>#</p>\n", $this->c->convert('#'));
        $this->assertSame("<p>##</p>\n", $this->c->convert('##'));
        $this->assertStringContainsString('<h1>x</h1>', $this->c->convert('# x'));
    }

    public function testEmptyAttributeBlockAfterNodeStaysLiteral(): void
    {
        // An empty `{}` abutting a word or inline node is literal, not consumed
        // (`hi{}`, `*x*{}`, the second `{}` in `[x]{}{}`). The `[x]{}` span form
        // (one empty block right after the bracket) still makes a span.
        $this->assertSame("<p>hi{}</p>\n", $this->c->convert('hi{}'));
        $this->assertSame("<p><strong>x</strong>{}</p>\n", $this->c->convert('*x*{}'));
        $this->assertSame("<p><span>x</span>{}</p>\n", $this->c->convert('[x]{}{}'));
        $this->assertSame("<p><span>x</span></p>\n", $this->c->convert('[x]{}'));
        // a comment-only block `{% ... %}` is still consumed (the comment vanishes)
        $this->assertSame("<p>a</p>\n", $this->c->convert('a{% note %}'));
    }

    public function testInlineAttributeBlockIsSingleLine(): void
    {
        // A newline before the closing `}` means it is not an inline attr block.
        $this->assertSame("<p>[x]{.a\n.b}</p>\n", $this->c->convert("[x]{.a\n.b}"));
    }

    public function testLeadingBomIsStripped(): void
    {
        // A leading UTF-8 BOM at the document start does not stop `# T` being a
        // heading; only at the very start (matches carve-js).
        $this->assertStringContainsString('<h1>T</h1>', $this->c->convert("\u{FEFF}# T"));
    }

    public function testNulByteIsReplacedWithReplacementChar(): void
    {
        // A NUL (U+0000) is replaced with U+FFFD so a control byte never reaches
        // output (decided cross-impl behavior). For carve-php this also prevents
        // a collision with the internal soft-break-guard sentinel.
        $this->assertSame("<p>a\u{FFFD}b</p>\n", $this->c->convert("a\0b"));
    }

    public function testSpanMarkerInFirstRowOrColumnIsAnEmptyCell(): void
    {
        // A `<` (colspan) in the first column or `^` (rowspan) in the first row
        // has nothing to merge into, so it renders as an empty cell rather than
        // being dropped (carve-js / carve-rs parity).
        $colspan = $this->c->convert("| < | b |\n|---|---|\n| c | d |");
        $this->assertStringContainsString('<th></th><th>b</th>', $colspan);
        $rowspan = $this->c->convert("| ^ | b |\n|---|---|\n| c | d |");
        $this->assertStringContainsString('<th></th><th>b</th>', $rowspan);
        // a normal colspan into a left cell still merges.
        $this->assertStringContainsString(
            '<td colspan="2">c</td>',
            $this->c->convert("| a | b |\n|---|---|\n| c | < |"),
        );
    }

    public function testGluedCellAttributesMatchInlineSourceOrderAndEdges(): void
    {
        $row1 = fn (string $src): string => explode("\n", $this->c->convert($src))[1];
        // glued `{...}` after the pipe sets the cell's attributes (source order).
        $this->assertSame(
            '  <thead><tr><th id="id" class="a" key="v">hi</th><th>b</th></tr></thead>',
            $row1("|{#id .a key=v} hi | b |\n|---|---|\n| c | d |"),
        );
        // a SPACE before the brace is literal content.
        $this->assertSame(
            '  <thead><tr><th>{.x} hi</th><th>b</th></tr></thead>',
            $row1("| {.x} hi | b |\n|---|---|\n| c | d |"),
        );
        // quoted brace in a value; partial-invalid stays literal; attributed
        // cell is never a bare span marker.
        $this->assertSame(
            '  <thead><tr><th key="{y}">hi</th><th>b</th></tr></thead>',
            $row1("|{key=\"{y}\"} hi | b |\n|---|---|\n| c | d |"),
        );
        $this->assertSame(
            '  <thead><tr><th>{.x 1bad} hi</th><th>b</th></tr></thead>',
            $row1("|{.x 1bad} hi | b |\n|---|---|\n| c | d |"),
        );
        $this->assertSame(
            '  <thead><tr><th class="x">&lt;</th><th>b</th></tr></thead>',
            $row1("|{.x} < | b |\n|---|---|\n| c | d |"),
        );
        // an attributed cell never makes the row a Carve all-`=` header row.
        $this->assertSame(
            "<table>\n  <tbody>\n    <tr><td class=\"x\">= A</td><td class=\"y\">= B</td></tr>\n  </tbody>\n</table>\n",
            $this->c->convert('|{.x} = A |{.y} = B |'),
        );
    }

    public function testUnquotedAttributeValuesAllowDotAndColon(): void
    {
        $this->assertSame("<p><span k=\"v.w\">a</span></p>\n", $this->c->convert('[a]{k=v.w}'));
        $this->assertSame("<p><span k=\"a:b\">a</span></p>\n", $this->c->convert('[a]{k=a:b}'));
    }

    public function testTwoPipesIsParagraphNotTable(): void
    {
        $this->assertSame("<p>||</p>\n", $this->c->convert('||'));
    }

    public function testAdjacentBlockAttributeLinesMergeForFollowingBlock(): void
    {
        $this->assertSame(
            "<section id=\"i\">\n  <h1 class=\"c\">H</h1>\n</section>\n",
            $this->c->convert("{.c}{#i}\n# H"),
        );
        $this->assertSame(
            "<section id=\"i\">\n  <h1 class=\"c\">H</h1>\n</section>\n",
            $this->c->convert("{#i}{.c}\n# H"),
        );
        $this->assertSame(
            "<section id=\"H\">\n  <h1 class=\"a b\">H</h1>\n</section>\n",
            $this->c->convert("{.a}{.b}\n# H"),
        );
    }

    public function testHeadingTrailingAttributeBlockStaysLiteral(): void
    {
        $this->assertSame(
            "<section id=\"H-c\">\n  <h1>H{.c}</h1>\n</section>\n",
            $this->c->convert('# H{.c}'),
        );
    }

    public function testInlineLinkDestinationRejectsSurroundingSpaces(): void
    {
        $this->assertSame("<p>[a]( u )</p>\n", $this->c->convert('[a]( u )'));
    }

    public function testInlineLinkRejectsTwoTitleStrings(): void
    {
        $this->assertSame("<p>[a](u “x” “y”)</p>\n", $this->c->convert('[a](u "x" "y")'));
    }

    public function testAsteriskSpaceStartsBulletList(): void
    {
        $this->assertSame("<ul>\n  <li>a *</li>\n</ul>\n", $this->c->convert('* a *'));
    }

    public function testReferenceDefinitionRequiresSpaceAfterColon(): void
    {
        $this->assertSame("<p>[a]:u</p>\n", $this->c->convert('[a]:u'));
    }

    public function testFootnoteDefinitionRequiresSpaceAfterColon(): void
    {
        $this->assertSame("<p>[^1]</p>\n<p>[^1]:x</p>\n", $this->c->convert("[^1]\n\n[^1]:x"));
    }
}
