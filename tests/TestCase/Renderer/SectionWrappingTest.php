<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 section 13: top-level headings are wrapped in `<section>`, and the
 * `sections` option turns that off.
 *
 * With the wrapper gone the id returns to the `<h*>` and the blocks that would
 * have been section children stay as siblings. That is the same shape a heading
 * inside a container has always rendered, which is the point of the option:
 * one placement rule for the whole document rather than two.
 *
 * Also pins PART 10 section 1 for an unwrapped heading. The author's own
 * attributes keep their source order and a generated one - the auto slug -
 * joins at the end, while an id the author wrote stays where they put it. All
 * three engines answered this differently before it was written down, because
 * the only way to reach it was a heading inside a container and no corpus case
 * gave such a heading attributes.
 */
class SectionWrappingTest extends TestCase
{
    protected function flat(string $source): string
    {
        $renderer = new HtmlRenderer();
        $renderer->setSectionWrapping(false);

        return trim(CarveConverter::create(renderer: $renderer)->convert($source));
    }

    protected function wrapped(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }

    public function testTheFlagIsOnByDefaultAndReadsBackWhatWasSet(): void
    {
        $renderer = new HtmlRenderer();
        $this->assertTrue($renderer->isSectionWrapping());

        $this->assertSame($renderer, $renderer->setSectionWrapping(false));
        $this->assertFalse($renderer->isSectionWrapping());

        $renderer->setSectionWrapping(true);
        $this->assertTrue($renderer->isSectionWrapping());
    }

    public function testWrapsByDefault(): void
    {
        $this->assertSame(
            "<section id=\"A\">\n  <h1>A</h1>\n  <p>p</p>\n</section>",
            $this->wrapped("# A\n\np\n"),
        );
    }

    public function testEmitsNoWrapperAndKeepsTheIdOnTheHeading(): void
    {
        $this->assertSame("<h1 id=\"A\">A</h1>\n<p>p</p>", $this->flat("# A\n\np\n"));
    }

    public function testFlattensNestedLevels(): void
    {
        $this->assertSame(
            "<h1 id=\"A\">A</h1>\n<p>p</p>\n<h2 id=\"B\">B</h2>\n<p>q</p>",
            $this->flat("# A\n\np\n\n## B\n\nq\n"),
        );
    }

    public function testFlattensAdjacentSameLevelHeadings(): void
    {
        $this->assertSame("<h1 id=\"A\">A</h1>\n<h1 id=\"B\">B</h1>", $this->flat("# A\n\n# B\n"));
    }

    public function testChangesNothingWithoutHeadings(): void
    {
        $source = "just a paragraph\n\n- and a list\n";
        $this->assertSame($this->wrapped($source), $this->flat($source));
    }

    public function testLeavesContainerHeadingsAlone(): void
    {
        $source = "> # Quoted\n>\n> Quoted body.\n\n:::\n# Divved\n:::\n";
        $this->assertSame($this->wrapped($source), $this->flat($source));
    }

    public function testATopLevelHeadingMatchesTheSameHeadingInsideADiv(): void
    {
        $inDiv = $this->wrapped(":::\n{a=b .c}\n# Same\n:::\n");
        $inner = implode("\n", array_map(
            static fn (string $line): string => preg_replace('/^ {2}/', '', $line) ?? $line,
            array_slice(explode("\n", $inDiv), 1, -1),
        ));

        $this->assertSame($inner, $this->flat("{a=b .c}\n# Same\n"));
    }

    public function testResolvesCrossReferencesAndImplicitHeadingReferences(): void
    {
        $this->assertSame(
            "<h1 id=\"Target\">Target</h1>\n"
                . '<p>See <a href="#Target">Target</a> and <a href="#Target">Target</a>.</p>',
            $this->flat("# Target\n\nSee </#target> and [Target][].\n"),
        );
    }

    public function testKeepsTheDedupNamespaceIntact(): void
    {
        $this->assertSame(
            "<h1 id=\"abc\">abc</h1>\n"
                . "<blockquote>\n  <h1 id=\"abc-2\">abc</h1>\n</blockquote>\n"
                . '<h1 id="abc-3">abc</h1>',
            $this->flat("# abc\n\n> # abc\n\n# abc\n"),
        );
    }

    public function testStillEmitsTheEndnotesRegion(): void
    {
        $html = $this->flat("# A\n\nText[^n].\n\n[^n]: Note.\n");

        $this->assertStringContainsString('<h1 id="A">A</h1>', $html);
        $this->assertStringContainsString('<section role="doc-endnotes" aria-label="Footnotes">', $html);
        $this->assertStringNotContainsString('<section id=', $html);
    }

    public function testAppendsAGeneratedIdAfterTheAuthorsAttributes(): void
    {
        $this->assertSame(
            "<blockquote>\n  <h1 a=\"b\" class=\"c\" id=\"Auto\">Auto</h1>\n</blockquote>",
            $this->wrapped("> {a=b .c}\n> # Auto\n"),
        );
        $this->assertSame(
            '<h1 a="b" class="c" id="Auto">Auto</h1>',
            $this->flat("{a=b .c}\n# Auto\n"),
        );
    }

    public function testKeepsAnAuthoredIdInItsSourcePosition(): void
    {
        // Written by the author, so not a generated attribute: it is not moved
        // to the end the way an auto slug is.
        $this->assertSame(
            "<blockquote>\n  <h1 id=\"x\" a=\"b\">Written</h1>\n</blockquote>",
            $this->wrapped("> {#x a=b}\n> # Written\n"),
        );
        $this->assertSame('<h1 id="x" a="b">Written</h1>', $this->flat("{#x a=b}\n# Written\n"));
    }
}
