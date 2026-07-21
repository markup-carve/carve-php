<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block header ("...") and grouping label ([...]) on the code-fence and
 * colon-fence openers (grammar PART 9 §2 / §12).
 *
 * Code fences: ``` lang "Header" [Label]. The header rides as the `title`
 * attribute on the <pre> (rendering A); the [label] is inert in core.
 * Divs: ::: type "Header" [Label] and bare ::: [Label]. PROPOSAL (graceful
 * degradation): when no group extension (e.g. tabs) consumes the div [label],
 * it is surfaced as a visible caption (<p class="div-label"> in HTML, a bold
 * line in Markdown/ANSI, a plain line in plain text) so the authored label is
 * not silently dropped. Diverges from the current spec corpus pending adoption.
 */
class CodeFenceHeaderTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testHeaderBecomesPreTitle(): void
    {
        $carve = <<<'CARVE'
        ```php "src/Auth.php"
        $ok = true;
        ```
        CARVE;
        $expected = "<pre title=\"src/Auth.php\"><code class=\"language-php\">\$ok = true;\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testHeaderAndLabelCombineLabelInert(): void
    {
        $carve = <<<'CARVE'
        ```php "src/Auth.php" [Composer]
        composer require x
        ```
        CARVE;
        $expected = "<pre title=\"src/Auth.php\"><code class=\"language-php\">composer require x\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testHeaderWithoutLanguage(): void
    {
        $carve = <<<'CARVE'
        ``` "notes.txt"
        remember the milk
        ```
        CARVE;
        $expected = "<pre title=\"notes.txt\"><code>remember the milk\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testPrecedingTitleAttributeWinsOverHeader(): void
    {
        $carve = <<<'CARVE'
        {title="from the attribute line"}
        ```php "from the header"
        code
        ```
        CARVE;
        $expected = "<pre title=\"from the attribute line\"><code class=\"language-php\">code\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testKeyValuePairStillFallsBackToInlineSpan(): void
    {
        $carve = <<<'CARVE'
        ```js title="x"
        code
        ```
        CARVE;
        $expected = "<p><code>js title=\"x\"\ncode\n</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testHeaderLabelWrongOrderFallsBackToInlineSpan(): void
    {
        $carve = <<<'CARVE'
        ```php [Composer] "x"
        code
        ```
        CARVE;
        $expected = "<p><code>php [Composer] \"x\"\ncode\n</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testMetadataGluedToLanguageFallsBack(): void
    {
        // Header/label must be space-separated from the language (grammar:
        // space+). A glued quote or bracket is not metadata -> inline span.
        $carve = "```php\"x\"\ncode\n```";
        $expected = "<p><code>php\"x\"\ncode\n</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testLabelGluedToHeaderFallsBack(): void
    {
        $carve = "```php \"x\"[Install]\ncode\n```";
        $expected = "<p><code>php \"x\"[Install]\ncode\n</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testLeadingEqualsLanguageWithHeaderFallsBack(): void
    {
        // A leading `=` is the raw-block opener's territory; a malformed raw
        // opener carrying a header must fall back, not become a code fence.
        $carve = "```=html \"x\"\ncode\n```";
        $expected = "<p><code>=html \"x\"\ncode\n</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testLanguageInfoContainingFenceCharacterFallsBack(): void
    {
        $this->assertSame(
            "<p>~~~~x~~~~</p>\n",
            $this->converter->convert('~~~~x~~~~'),
        );
    }

    public function testBlankLinesInsideCodeFenceArePreserved(): void
    {
        $this->assertSame(
            "<pre><code>\n\n</code></pre>\n",
            $this->converter->convert("```\n\n\n```"),
        );
    }

    public function testColumnZeroCodeFenceOpenerOpensAtDocumentLevel(): void
    {
        $this->assertSame(
            "<pre><code>code\n</code></pre>\n",
            $this->converter->convert("```\ncode\n```"),
        );
    }

    public function testIndentedCodeFenceOpenersAreParagraphTextAtDocumentLevel(): void
    {
        foreach ([1, 2, 3, 4] as $indent) {
            $source = str_repeat(' ', $indent) . "```\ncode\n" . str_repeat(' ', $indent) . '```';
            $html = $this->converter->convert($source);

            $this->assertStringNotContainsString('<pre><code>', $html, 'indent ' . $indent);
            $this->assertStringContainsString('code', $html, 'indent ' . $indent);
        }
    }

    public function testCodeFenceOpenerAtListItemContentColumnOpens(): void
    {
        $html = $this->converter->convert("- ```\n  code\n  ```\n");

        $this->assertStringContainsString('<pre><code>code', $html);
    }

    public function testCodeFenceOpenerOnListItemContinuationLineOpens(): void
    {
        // The opener is not on the marker line but on a later continuation line
        // at the item's content column (spec corpus 84-list-lazy-continuation-7).
        $this->assertSame(
            "<ul>\n  <li>item\n    <pre><code>c\n</code></pre>\n  </li>\n</ul>\n<p>tail</p>\n",
            $this->converter->convert("- item\n  ```\n  c\n  ```\ntail"),
        );
    }

    public function testCodeFenceOpenerIndentedPastListItemContentColumnDoesNotOpen(): void
    {
        $html = $this->converter->convert("- item\n   ```\n   code\n   ```\n");

        $this->assertStringNotContainsString('<pre><code>', $html);
        $this->assertStringContainsString('code', $html);
    }

    public function testCodeFenceOpenerAtBlockQuoteContentColumnOpens(): void
    {
        $html = $this->converter->convert("> ```\n> code\n> ```\n");

        $this->assertStringContainsString("<blockquote>\n  <pre><code>code", $html);
    }

    public function testCodeFenceCloserIndentedPastOpenerDoesNotClose(): void
    {
        $this->assertSame(
            "<pre><code>code\n ```\nstill code\n</code></pre>\n",
            $this->converter->convert("```\ncode\n ```\nstill code\n```"),
        );
    }

    public function testIndentedBacktickFenceLineSurvivesAsCodeSampleText(): void
    {
        $this->assertSame(
            "<pre><code>sample:\n ```\n</code></pre>\n",
            $this->converter->convert("```\nsample:\n ```\n```"),
        );
    }

    public static function referencePrepassListNestedFenceProvider(): array
    {
        return [
            'fence on bullet marker line' => ["- ```\n  [r]: /u\n  ```\n\n[r][]"],
            'bullet item' => ["- one\n  ```\n  [r]: /u\n  ```\n\n[r][]"],
            'decimal ordered item' => ["1. one\n   ```\n   [r]: /u\n   ```\n\n[r][]"],
            'alpha ordered item' => ["a. one\n   ```\n   [r]: /u\n   ```\n\n[r][]"],
            'roman ordered item' => ["i. one\n   ```\n   [r]: /u\n   ```\n\n[r][]"],
            'attributed bullet item' => ["-{.c} one\n      ```\n      [r]: /u\n      ```\n\n[r][]"],
        ];
    }

    #[DataProvider('referencePrepassListNestedFenceProvider')]
    public function testReferencePrepassSkipsDefinitionsInListNestedFence(string $source): void
    {
        $html = $this->converter->convert($source);

        $this->assertStringNotContainsString('<a href="/u">', $html);
        $this->assertStringContainsString('[r][]', $html);
    }

    public static function referencePrepassFenceCloserAsymmetryProvider(): array
    {
        return [
            'literal bullet marker in document fence' => ["```\n- ```\n[r]: /u\n```\n\n[r][]"],
            'literal blockquote marker in document fence' => ["```\n> ```\n[r]: /u\n```\n\n[r][]"],
            'literal ordered marker in document fence' => ["```\n1. ```\n[r]: /u\n```\n\n[r][]"],
            'quoted fence' => ["> ```\n> [r]: /u\n> ```\n\n[r][]"],
        ];
    }

    #[DataProvider('referencePrepassFenceCloserAsymmetryProvider')]
    public function testReferencePrepassSkipsDefinitionsInsideFences(string $source): void
    {
        $html = $this->converter->convert($source);

        $this->assertStringNotContainsString('<a href="/u">', $html);
        $this->assertStringContainsString('[r][]', $html);
    }

    public static function referencePrepassCollectsDefinitionAfterFenceProvider(): array
    {
        return [
            'quoted fence in bullet item' => ["- > ```\n  > code\n  > ```\n\n[r]: /u\n\n[r][]"],
            'quoted fence in task item' => ["- [ ] > ```\n  > code\n  > ```\n\n[r]: /u\n\n[r][]"],
        ];
    }

    #[DataProvider('referencePrepassCollectsDefinitionAfterFenceProvider')]
    public function testReferencePrepassCollectsDefinitionsAfterQuotedListFences(string $source): void
    {
        $html = $this->converter->convert($source);

        $this->assertStringContainsString('<a href="/u">r</a>', $html);
    }

    public function testReferencePrepassSkipsDefinitionsInFenceNestedTwoListsDeep(): void
    {
        $html = $this->converter->convert("- one\n  - two\n    ```\n    [r]: /u\n    ```\n\n[r][]");

        $this->assertStringNotContainsString('<a href="/u">', $html);
        $this->assertStringContainsString('[r][]', $html);
    }

    public function testReferencePrepassSkipsExplicitReferenceDefinitionInFenceOnMarkerLine(): void
    {
        $html = $this->converter->convert("- ```\n  [r]: /nope\n  ```\n\n[x][r]");

        $this->assertStringNotContainsString('<a href="/nope">', $html);
        $this->assertStringContainsString('[x][r]', $html);
    }

    public function testReferencePrepassStillCollectsDefinitionAfterDocumentIndentedRun(): void
    {
        $html = $this->converter->convert(" ```\n[r]: /u\n\n[x][r]");

        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testReferencePrepassStillCollectsForwardReferenceDefinition(): void
    {
        $html = $this->converter->convert("[x][r]\n\n[r]: /u");

        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDivHeaderAndLabelRendersBothCaptions(): void
    {
        // PROPOSAL (graceful degradation): when no extension consumes the
        // grouping `[label]`, it is surfaced as a <p class="div-label"> caption
        // after the quoted title's <p class="admonition-title">. Diverges from
        // the current spec corpus (label was inert) pending adoption.
        $carve = <<<'CARVE'
        ::: tip "Pro Tip" [Build]
        Save early, save often.
        :::
        CARVE;
        $expected = "<aside class=\"admonition tip\">\n"
            . "  <p class=\"admonition-title\">Pro Tip</p>\n"
            . "  <p class=\"div-label\">Build</p>\n"
            . "  <p>Save early, save often.</p>\n"
            . "</aside>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testBareDivWithLabelRendersLabelCaption(): void
    {
        // PROPOSAL (graceful degradation): an unconsumed `[label]` surfaces as
        // a <p class="div-label"> caption rather than being dropped.
        $carve = <<<'CARVE'
        ::: [First]
        First panel.
        :::
        CARVE;
        $expected = "<div>\n"
            . "  <p class=\"div-label\">First</p>\n"
            . "  <p>First panel.</p>\n"
            . "</div>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testLabelCaptionRendersInMarkdown(): void
    {
        // PROPOSAL: the unconsumed grouping label survives into Markdown as a
        // leading bold line, mirroring the quoted-title fallback.
        $carve = <<<'CARVE'
        ::: tab [Installation]
        Run the installer.
        :::
        CARVE;
        $expected = "**Installation**\n\nRun the installer.";

        $markdown = (new MarkdownRenderer())->render($this->converter->parse($carve));
        $this->assertSame($expected, trim($markdown));
    }
}
