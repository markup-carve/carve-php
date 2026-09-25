<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `::: footnotes` marker places the endnotes section only at document top
 * level (PART 9 §16, `CARVE-P9-073`). Inside a block-level container it renders
 * the `<div class="footnotes">` floor of §12 where it is written, and the
 * section is appended where an unmarked document puts it.
 *
 * carve-php relocated the section into the container instead, so
 * `role="doc-endnotes"` was announced nested in a quotation and the document's
 * own notes read as part of what was quoted (carve-php#2372). The block-quote
 * row is byte-identical to the spec corpus case
 * `497-a-footnotes-placement-marker-inside-a-container-does-not-place`.
 *
 * The last two rows are the controls: a top-level marker and an unmarked
 * document are untouched by the refusal.
 */
class AFootnotesPlacementMarkerInsideAContainerDoesNotPlaceTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testRequiredOutput(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseProvider(): array
    {
        $endnotes = "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . '      <p>only note<a href="#fnref1" role="doc-backlink"'
            . " aria-label=\"Back to reference\">\u{21a9}</a></p>\n"
            . "    </li>\n  </ol>\n</section>";
        $intro = "<p>Intro<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n";

        return [
            'block quote' => [
                "Intro[^a].\n\n> ::: footnotes\n> :::\n\n[^a]: only note\n",
                $intro . "<blockquote>\n  <div class=\"footnotes\">\n\n  </div>\n</blockquote>\n" . $endnotes,
            ],
            'list item' => [
                "Intro[^a].\n\n- ::: footnotes\n  :::\n\n[^a]: only note\n",
                $intro . "<ul>\n  <li>\n    <div class=\"footnotes\">\n\n    </div>\n  </li>\n</ul>\n" . $endnotes,
            ],
            'directive body' => [
                "Intro[^a].\n\n::: note\n::: footnotes\n:::\n:::\n\n[^a]: only note\n",
                $intro . "<aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "  <div class=\"footnotes\">\n\n  </div>\n</aside>\n" . $endnotes,
            ],
            'definition description' => [
                "Intro[^a].\n\n:: t\n: ::: footnotes\n  :::\n\n[^a]: only note\n",
                $intro . "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"footnotes\">\n\n"
                    . "    </div>\n  </dd>\n</dl>\n" . $endnotes,
            ],
            'control: document top level' => [
                "Intro[^a].\n\n::: footnotes\n:::\n\n[^a]: only note\n",
                $intro . $endnotes,
            ],
            'control: no marker at all' => [
                "Intro[^a].\n\n[^a]: only note\n",
                $intro . $endnotes,
            ],
        ];
    }

    /**
     * A marker inside a footnote definition degrades like any other, and its
     * floor renders inside the very section it failed to move - so the section
     * still appears exactly once.
     */
    public function testAMarkerInsideAFootnoteDefinitionLeavesOneSection(): void
    {
        $out = (new CarveConverter())->convert("Intro[^a].\n\n[^a]: ::: footnotes\n    :::\n");
        $this->assertSame(1, substr_count($out, 'role="doc-endnotes"'));
        $this->assertStringContainsString("  <div class=\"footnotes\">\n", $out);
    }

    /**
     * A marker inside a container does not consume the placement: a top-level
     * marker written after it still places, and there is still one section.
     */
    public function testAContainedMarkerDoesNotConsumeTheOnePlacement(): void
    {
        $out = (new CarveConverter())->convert(
            "Intro[^a].\n\n> ::: footnotes\n> :::\n\n::: footnotes\n:::\n\n## After\n\n[^a]: only note\n",
        );
        $this->assertSame(1, substr_count($out, 'role="doc-endnotes"'));
        // Past the quote the contained marker sits in, and still before the
        // heading that follows the top-level marker.
        $this->assertLessThan((int)strpos($out, 'role="doc-endnotes"'), (int)strpos($out, '</blockquote>'));
        $this->assertLessThan((int)strpos($out, '<h2'), (int)strpos($out, 'role="doc-endnotes"'));
    }
}
