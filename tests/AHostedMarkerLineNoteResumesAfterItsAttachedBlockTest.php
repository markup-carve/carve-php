<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After the block its marker-line `+` takes, an item-hosted note resumes at its body column (carve#5512 ruling).
 */
final class AHostedMarkerLineNoteResumesAfterItsAttachedBlockTest extends TestCase
{
    public function testUnclosedFenceGoesIntoTheNote(): void
    {
        $expected = <<<'HTML'
<ul>
  <li></li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>p</p>
      <pre><code class="language-x">[^z]: code

</code></pre>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("- [^a]: +\n  p\n    ``` x\n    [^z]: code\n\nsee[^a]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bodyColumnBlocks(): array
    {
        return [
            'closed fence' => ["- [^a]: +\n  p\n    ``` x\n    code\n    ```\n\nsee[^a]\n", "<p>p</p>\n      <pre><code class=\"language-x\">code\n</code></pre>"],
            'quote' => ["- [^a]: +\n  p\n    > q\n\nsee[^a]\n", "<p>p</p>\n      <blockquote><p>q</p></blockquote>"],
            'list' => ["- [^a]: +\n  p\n    - i\n\nsee[^a]\n", "<p>p</p>\n      <ul>\n        <li>i</li>\n      </ul>"],
            'heading' => ["- [^a]: +\n  p\n    # z\n\nsee[^a]\n", "<p>p</p>\n      <h1 id=\"z\">z</h1>"],
            'after a blank' => ["- [^a]: +\n  p\n\n    > q\n\nsee[^a]\n", "<p>p</p>\n      <blockquote><p>q</p></blockquote>"],
            'ordered item' => ["1. [^a]: +\n   p\n     > q\n\nsee[^a]\n", "<p>p</p>\n      <blockquote><p>q</p></blockquote>"],
        ];
    }

    #[DataProvider('bodyColumnBlocks')]
    public function testBlockAtTheNoteBodyColumnStaysInTheNote(string $source, string $needle): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("  <li></li>\n", $html);
        self::assertStringContainsString($needle, $html);
    }

    public function testBlockAtTheItemColumnStaysInTheItem(): void
    {
        $html = (new CarveConverter())->convert("- [^a]: +\n  p\n  ``` x\n  code\n  ```\n\nsee[^a]\n");

        self::assertStringContainsString("<ul>\n  <li>\n    <pre><code class=\"language-x\">code", $html);
    }
}
