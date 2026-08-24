<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * THE SECOND SPELLING of "a task item's checkbox is content, not marker".
 *
 * This engine says the rule twice. The AST writer says it in
 * CarveRenderer::renderList(); the HTML importer writes Carve source directly
 * rather than through that writer, so HtmlToCarve::processNode() has to say it
 * again - and both said it wrong the same way (carve-php#1693, umbrella
 * markup-carve/carve#1690).
 *
 * The importer half is the HARMFUL one. Where the writer half only chose a
 * different valid spelling of the same document, this one did not read back: a
 * block opener indented to the column after the checkbox opens nothing, so it
 * was absorbed into the marker line's paragraph and the visible text moved from
 * `h` to `# h` - the defect carve-js reported as carve-js#1450.
 *
 * carve-js has ONE spelling, because its importer builds an AST and hands it to
 * the writer, so fixing the writer fixed both. Nothing in this engine's tests
 * reached the second site, which is why it is pinned here separately rather
 * than folded into the writer's own test.
 */
class ATaskItemSCheckboxIsNotPartOfItsMarkerOnImportTest extends TestCase
{
    private HtmlToCarve $importer;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->importer = new HtmlToCarve();
        $this->converter = new CarveConverter();
    }

    /**
     * The exact carve-js#1450 repro. The import is asserted, and then it is
     * RENDERED BACK - which is the assertion the column actually decides.
     * Written at the post-checkbox column the heading is not a heading, and the
     * second assertion is the one that fails.
     */
    public function testAnImportedHeadingIdOnATaskItemReadsBack(): void
    {
        $html = "<ul>\n  <li><input type=\"checkbox\" checked disabled> \n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>";

        $imported = $this->importer->convert($html);
        $this->assertSame("- [x] {#h}\n  # h\n", $imported);
        $this->assertSame($html, trim($this->converter->convert($imported)));
    }

    /**
     * Not only the floating attribute the ticket reports. EVERY block opener
     * after the item's first was lost the same way - a heading, a quote and a
     * fence alike. An ordinary paragraph survives being written four columns
     * too far in, which is why this had no symptom until a block opener was
     * forced onto a continuation.
     */
    public function testAnImportedHeadingAfterAFirstParagraphReadsBackAsAHeading(): void
    {
        $imported = $this->importer->convert(
            "<ul>\n  <li><input type=\"checkbox\" checked disabled> a\n    <h2 id=\"t\">t</h2>\n  </li>\n</ul>",
        );

        $this->assertSame("- [x] a\n\n  {#t}\n  ## t\n", $imported);
        $this->assertStringContainsString('<h2 id="t">t</h2>', $this->converter->convert($imported));
    }

    public function testAnImportedQuoteReadsBackAsAQuote(): void
    {
        $imported = $this->importer->convert(
            "<ul>\n  <li><input type=\"checkbox\" disabled> a\n    <blockquote>\n      <p>q</p>\n    </blockquote>\n  </li>\n</ul>",
        );

        $this->assertSame("- [ ] a\n\n  > q\n", $imported);
        $this->assertStringContainsString('<blockquote>', $this->converter->convert($imported));
    }

    public function testAnImportedFenceReadsBackAsAFence(): void
    {
        $imported = $this->importer->convert(
            "<ul>\n  <li><input type=\"checkbox\" checked disabled> a\n    <pre><code class=\"language-php\">1;\n</code></pre>\n  </li>\n</ul>",
        );

        $this->assertSame("- [x] a\n\n  ```php\n  1;\n  ```\n", $imported);
        $this->assertStringContainsString('<code class="language-php">', $this->converter->convert($imported));
    }

    /**
     * Item attributes DO widen the marker, so they DO move the column: `-{#k} `
     * is six wide and the ten of `-{#k} [x] ` is not the column. A fix that
     * pinned every task item at 2 would move this one.
     *
     * ONLY THE WRITTEN COLUMN IS ASSERTED HERE, deliberately. The three engines'
     * READERS do not agree what `-{#k} [x] `'s content column is - carve-js
     * reads 6, this engine and carve-rs read 2 - so this shape does not
     * round-trip here whichever column is written, and it did not before this
     * fix either (it was written at 10 and lost the same way). That divergence
     * is a parser question, filed separately; it is not what this fix decides,
     * and asserting a round-trip here would pin a reading this engine does not
     * have.
     */
    public function testItemAttributesStillWidenTheColumn(): void
    {
        $this->assertSame(
            "-{#k} [x] {#h}\n      # h\n",
            $this->importer->convert(
                "<ul>\n  <li id=\"k\"><input type=\"checkbox\" checked disabled> \n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>",
            ),
        );
    }

    /**
     * The control: a plain item never had the defect, because its content
     * column and its post-marker column are the same. It holds on both sides
     * of the fix.
     */
    public function testAPlainItemIsUnchanged(): void
    {
        $html = "<ul>\n  <li>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>";

        $imported = $this->importer->convert($html);
        $this->assertSame("- {#h}\n  # h\n", $imported);
        $this->assertSame($html, trim($this->converter->convert($imported)));
    }
}
