<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R4 as extended by markup-carve/carve#957: the clause binds every
 * consumer that derives display text from a heading, not the cross-reference
 * alone, so a numbered cross-reference label and a table-of-contents entry
 * clone the same inline NODES. A render-stage transform may not undo a core
 * resolution rule.
 */
class DerivedDisplayTextClonesTheSameNodesTest extends TestCase
{
    /**
     * The discriminating document: a heading whose quotes and dash have a source
     * run distinct from their resolved glyphs, with a cross-reference to it.
     *
     * @var string
     */
    private const SOURCE = "# The \"quoted\" -- heading\n\nSee </#The-quoted-heading>\n";

    public function testANumberedCrossReferenceLabelKeepsTheSourceRun(): void
    {
        // The heading and the label it derives from read the same way. Before
        // the fix the label carried glyphs one line under a heading carrying the
        // typed run, which is the failure carve-php#1004 fixed for the plain
        // cross-reference, reintroduced at a second derivation site.
        $this->assertSame(
            "<section id=\"The-quoted-heading\">\n  <h1><span class=\"section-number\">1</span> The \"quoted\" -- heading</h1>\n  <p>See <a href=\"#The-quoted-heading\">Section 1 - The \"quoted\" -- heading</a></p>\n</section>\n",
            $this->html(
                self::SOURCE,
                SmartTypographyMode::Source,
                new HeadingNumbersExtension(),
            ),
        );
    }

    public function testANumberedCrossReferenceLabelStillGlyphsByDefault(): void
    {
        // The CONTROL - the default mode is unchanged, so the fix moved the
        // decision to the renderer rather than moving the answer.
        $this->assertSame(
            "<section id=\"The-quoted-heading\">\n  <h1><span class=\"section-number\">1</span> The \u{201C}quoted\u{201D} \u{2013} heading</h1>\n  <p>See <a href=\"#The-quoted-heading\">Section 1 - The \u{201C}quoted\u{201D} \u{2013} heading</a></p>\n</section>\n",
            $this->html(
                self::SOURCE,
                SmartTypographyMode::Glyph,
                new HeadingNumbersExtension(),
            ),
        );
    }

    public function testATableOfContentsEntryKeepsTheSourceRun(): void
    {
        // The entry is ESCAPED ONCE, by the renderer that renders its nodes, so
        // a `"` in a heading reaches the entry bare - PART 10 §2 escapes `&`,
        // `<` and `>` in text content and not quotes. It used to be escaped a
        // second time by the list builder, which published `&quot;` where the
        // heading published `"`; carve-js emits the bare quote.
        $this->assertSame(
            "<nav class=\"toc\">\n<ul>\n<li><a href=\"#The-quoted-heading\">The \"quoted\" -- heading</a></li>\n</ul>\n</nav>\n<section id=\"The-quoted-heading\">\n  <h1>The \"quoted\" -- heading</h1>\n</section>\n",
            $this->html(
                "::: toc\n:::\n\n# The \"quoted\" -- heading\n",
                SmartTypographyMode::Source,
                new TocPlacementExtension(),
            ),
        );
    }

    public function testTheInjectedTocEntryKeepsTheSourceRunToo(): void
    {
        $html = $this->html(
            "# The \"quoted\" -- heading\n",
            SmartTypographyMode::Source,
            new TableOfContentsExtension(position: 'top'),
        );

        $this->assertStringContainsString('The "quoted" -- heading', $html);
        $this->assertStringNotContainsString("The \u{201C}quoted\u{201D} \u{2013} heading", $html);
    }

    public function testTheLabelIsTakenBeforeTheSectionNumberInjection(): void
    {
        // THE LABEL IS TAKEN BEFORE ANY RENDER-STAGE INJECTION. The label is
        // 'Beta', not '1.1 Beta' - the section-number span this very extension
        // injects is not part of the heading's authored content. This engine
        // already answered this way; it is pinned because the clause makes it
        // normative and because this extension is the thing doing the injecting.
        $this->assertSame(
            "<section id=\"Alpha\">\n  <h1><span class=\"section-number\">1</span> Alpha</h1>\n  <section id=\"Beta\">\n    <h2><span class=\"section-number\">1.1</span> Beta</h2>\n    <p>See <a href=\"#Beta\">Beta</a></p>\n  </section>\n</section>\n",
            $this->html(
                "# Alpha\n\n## Beta\n\nSee </#Beta>\n",
                SmartTypographyMode::Glyph,
                new HeadingNumbersExtension(crossref: 'title'),
            ),
        );
    }

    public function testAHeadingsInlineMarkupIsClonedNotFlattened(): void
    {
        // DERIVED DISPLAY TEXT CLONES THE SAME NODES (PART 9R R4,
        // markup-carve/carve#957). The heading's own markup comes with the
        // label: a code span reaches the anchor as a code span, not as its bare
        // content. The label used to stay FLAT here, which answered R4's
        // source-run half and left its markup half unanswered - and it is the
        // node that carries both, so one seam settles them together.
        $this->assertSame(
            "<section id=\"The-code-heading\">\n  <h1><span class=\"section-number\">1</span> The <code>code</code> heading</h1>\n  <p>See <a href=\"#The-code-heading\">The <code>code</code> heading</a></p>\n</section>\n",
            $this->html(
                "# The `code` heading\n\nSee </#The-code-heading>\n",
                SmartTypographyMode::Glyph,
                new HeadingNumbersExtension(crossref: 'title'),
            ),
        );
    }

    /**
     * Render a source string with the supplied smart typography mode and
     * extensions, preserving extension registration order.
     */
    private function html(string $source, SmartTypographyMode $mode, object ...$extensions): string
    {
        $renderer = (new HtmlRenderer())->setSmartTypography($mode);
        $converter = new CarveConverter(renderer: $renderer);

        foreach ($extensions as $extension) {
            $converter->addExtension($extension);
        }

        return $converter->convert($source);
    }
}
