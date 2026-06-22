<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Block header ("...") and grouping label ([...]) on the code-fence and
 * colon-fence openers (grammar PART 9 §2 / §12).
 *
 * Code fences: ``` lang "Header" [Label]. The header rides as the `title`
 * attribute on the <pre> (rendering A); the [label] is inert in core.
 * Divs: ::: type "Header" [Label] and bare ::: [Label]; the [label] is inert
 * in core (a group extension such as tabs consumes it).
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

    public function testDivHeaderAndLabelLabelInert(): void
    {
        $carve = <<<'CARVE'
        ::: tip "Pro Tip" [Build]
        Save early, save often.
        :::
        CARVE;
        $expected = "<aside class=\"admonition tip\">\n"
            . "  <p class=\"admonition-title\">Pro Tip</p>\n"
            . "  <p>Save early, save often.</p>\n"
            . "</aside>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }

    public function testBareDivWithLabelRendersPlainDiv(): void
    {
        $carve = <<<'CARVE'
        ::: [First]
        First panel.
        :::
        CARVE;
        $expected = "<div>\n  <p>First panel.</p>\n</div>\n";

        $this->assertSame($expected, $this->converter->convert($carve));
    }
}
