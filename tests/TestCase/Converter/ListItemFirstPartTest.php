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
     * A MULTI-LINE part puts its FIRST LINE on the marker line and indents the
     * rest, rather than going below it whole. Going below left `- ` alone,
     * which is not a marker (markup-carve/carve-php#1224).
     *
     * The blank line inside the container is kept as a blank line: it separates
     * the blocks within the part, and dropping it ran them together.
     */
    public function testAMultiLinePartPutsItsFirstLineOnTheMarker(): void
    {
        // Two body paragraphs, so the part still holds the blank line this
        // guards. The summary is no longer one of them: it is the disclosure's
        // label and is written as the opener's quoted title.
        $carve = (new HtmlToCarve())->convert(
            '<ul><li><details><summary>Title</summary><p>Body</p><p>More</p></details></li></ul>',
        );

        $this->assertSame("- ::: details \"Title\"\n  Body\n\n  More\n  :::\n", $carve);
        $this->assertStringNotContainsString('<p>-</p>', $this->render($carve));
        $this->assertStringContainsString('class="details"', $this->render($carve));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function codeSpanProvider(): array
    {
        return [
            'content starting with a backtick' => ['`start', '`` `start ``'],
            'content ending with a backtick' => ['end `', '`` end ` ``'],
            'both ends' => ['`both`', '`` `both` ``'],
            // A space already in the content is CONTENT. A reader eats one from
            // each end regardless, so the pad goes on anyway or the author's
            // own space is what gets eaten.
            'a leading space and a backtick' => [' lead`', '``  lead` ``'],
            'a backtick and a trailing space' => ['`trail ', '`` `trail  ``'],
        ];
    }

    /**
     * A code span is padded on BOTH sides or neither
     * (markup-carve/carve-php#1224). A reader strips one space from each end
     * only when there is one at each end, so a single-sided pad stayed in the
     * content and came back as part of the code.
     */
    #[DataProvider('codeSpanProvider')]
    public function testACodeSpanIsPaddedOnBothSides(string $inner, string $carve): void
    {
        $produced = (new HtmlToCarve())->convert('<p><code>' . $inner . '</code></p>');

        $this->assertSame($carve . "\n", $produced);

        preg_match('#<code>(.*)</code>#s', $this->render($produced), $match);
        $this->assertSame($inner, $match[1] ?? null);
    }

    /**
     * BOUND: a code span that needs no pad gets none.
     */
    public function testAnOrdinaryCodeSpanIsNotPadded(): void
    {
        $this->assertSame("`plain`\n", (new HtmlToCarve())->convert('<p><code>plain</code></p>'));
    }
}
