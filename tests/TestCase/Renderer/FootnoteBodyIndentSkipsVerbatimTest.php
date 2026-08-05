<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Indenting a footnote body must not reach inside verbatim content.
 *
 * `indentFootnoteBody()` padded every line starting with a tag. `</code></pre>`
 * starts with a tag, so it was padded - and that padding sits INSIDE the `<pre>`,
 * giving the rendered code trailing whitespace the author never wrote
 * (carve-php#815).
 *
 * `indentBlock()`, which does the same job for list items, has always tracked
 * `<pre>` for exactly this reason. This is the same guard, in the function that
 * was missing it.
 *
 * Not a regression from tonight's footnote work: bisected back through
 * carve-php#812, #809, #808, #805 and #802, and the indented closer is present in
 * all of them.
 */
class FootnoteBodyIndentSkipsVerbatimTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testAClosingFenceInANoteBodyIsNotIndented(): void
    {
        $html = $this->html("[^a]: note\n  ```\n  code\n  ```\n\nsee[^a]\n");

        // The opener carries the body's indent; the closer sits at column 0, so
        // no whitespace lands inside the `<pre>`.
        $this->assertStringContainsString("      <pre><code>code\n</code></pre>", $html);
    }

    public function testTheCodeItselfCarriesNoTrailingWhitespace(): void
    {
        $html = $this->html("[^a]: note\n  ```\n  code\n  ```\n\nsee[^a]\n");

        $this->assertMatchesRegularExpression('/<code>code\n<\/code>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<code>code\n +<\/code>/', $html);
    }

    public function testAMultiLineBlockKeepsItsOwnIndentationOnly(): void
    {
        // Interior lines were never padded (only tag-leading ones were), so this
        // pins that the fix did not start padding them either.
        $html = $this->html("[^a]: note\n  ```\n  a\n    b\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("<pre><code>a\n  b\n</code></pre>", $html);
    }

    public function testABlockAFTERTheFenceIsStillIndented(): void
    {
        // The guard must clear on the closer. A paragraph following the fence is
        // a block-boundary line and still gets the body's six spaces.
        $html = $this->html("[^a]: note\n  ```\n  code\n  ```\n\n  after\n\nsee[^a]\n");

        // The final paragraph carries the backlink, so match the opening only.
        $this->assertStringContainsString('      <p>after', $html);
    }

    public function testAPlainNoteBodyIsUnchanged(): void
    {
        // No verbatim content at all - the path the fix must not disturb.
        $html = $this->html("[^a]: note\n\nsee[^a]\n");

        $this->assertStringContainsString('      <p>note', $html);
    }
}
