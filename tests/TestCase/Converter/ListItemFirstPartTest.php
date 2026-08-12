<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A single-line list item stays on its marker line
 * (markup-carve/carve-php#1217).
 *
 * Pushing it below left `- ` alone, and a marker with nothing after it is not
 * a marker: the item came back as a paragraph reading `-`, with its content
 * outside the list. That happened for text that merely LOOKED like a block
 * (`<li>|start</li>`) and for real blocks alike.
 *
 * Asserted through the renderer, because the claim is that the item survives,
 * not which spelling was chosen. carve-js and carve-rs produce the same
 * spelling and the same result.
 */
class ListItemFirstPartTest extends TestCase
{
    private function render(string $carve): string
    {
        return CarveConverter::create()->convert($carve);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function itemProvider(): array
    {
        return [
            'text starting with a table marker' => ['<ul><li>|start</li></ul>', "- |start\n", '<li>|start</li>'],
            'text starting with a quote marker' => ['<ul><li>>start</li></ul>', "- >start\n", '<li>&gt;start</li>'],
            'a real blockquote' => ['<ul><li><blockquote>q</blockquote></li></ul>', "- > q\n", '<blockquote>'],
            'a real table' => ['<ul><li><table><tr><td>c</td></tr></table></li></ul>', "- | c |\n", '<table>'],
            'a real heading' => ['<ul><li><h2>Head</h2></li></ul>', "- ## Head\n", '<h2'],
            'an ordered item' => ['<ol><li>|x</li></ol>', "1. |x\n", '<li>|x</li>'],
        ];
    }

    #[DataProvider('itemProvider')]
    public function testTheItemSurvives(string $html, string $carve, string $rendered): void
    {
        $produced = (new HtmlToCarve())->convert($html);

        $this->assertSame($carve, $produced);
        $this->assertStringContainsString($rendered, $this->render($produced));
        // The tell of the old defect: a paragraph holding a bare marker.
        $this->assertStringNotContainsString('<p>-</p>', $this->render($produced));
    }

    /**
     * BOUND: an ordinary item never took the block path and is unaffected.
     * Here so the fix is not credited with the common case.
     */
    public function testAnOrdinaryItemIsUnchanged(): void
    {
        $this->assertSame("- normal\n", (new HtmlToCarve())->convert('<ul><li>normal</li></ul>'));
    }

    /**
     * BOUND: a MULTI-LINE part still goes below the marker, because it cannot
     * share the line. This is the one branch the change keeps.
     *
     * That spelling has its own round-trip problem, which is not this fix and
     * is not asserted here.
     */
    public function testAMultiLinePartStillGoesBelowTheMarker(): void
    {
        $this->assertSame(
            "- \n\n  ::: details\n  Title\n\n  Body\n  :::\n",
            (new HtmlToCarve())->convert('<ul><li><details><summary>Title</summary><p>Body</p></details></li></ul>'),
        );
    }
}
