<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
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

    public function testClassesAreNotDeduplicated(): void
    {
        // grammar §15: classes accumulate in source order, no de-dup
        $this->assertSame('<p><span class="a a">x</span></p>' . "\n", $this->c->convert('[x]{.a .a}'));
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
}
