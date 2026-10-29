<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P2-033: a top-level GitHub alert blockquote imports as an admonition.
 * Ported from carve-js#3869; corpus-convert cases 88-100 pin the same rulings.
 */
class AMarkdownGithubAlertImportsAsAnAdmonitionTest extends TestCase
{
    private function imported(string $markdown): string
    {
        return rtrim((new MarkdownToCarve())->convert($markdown), "\n");
    }

    /**
     * @return array<string> Top-level block types of the parsed Carve.
     */
    private function blockTypes(string $carve): array
    {
        return array_map(
            static fn (Node $node): string => $node::class,
            CarveConverter::carve()->parse($carve)->getChildren(),
        );
    }

    private function containsAdmonition(Node $node): bool
    {
        if ($node instanceof Div && $node->admonitionKind() !== null) {
            return true;
        }
        foreach ($node->getChildren() as $child) {
            if ($this->containsAdmonition($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'NOTE' => ['NOTE', 'note', 'Note'],
            'TIP' => ['TIP', 'tip', 'Tip'],
            'IMPORTANT' => ['IMPORTANT', 'info', 'Info'],
            'WARNING' => ['WARNING', 'warning', 'Warning'],
            'CAUTION' => ['CAUTION', 'danger', 'Danger'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testTheLabelImportsAsItsAdmonition(string $label, string $kind, string $name): void
    {
        $carve = $this->imported("> [!{$label}]\n> Back up first.");

        $this->assertSame("::: {$kind}\nBack up first.\n:::", $carve);
        $this->assertSame(
            "<aside class=\"admonition {$kind}\" aria-label=\"{$name}\">\n  <p>Back up first.</p>\n</aside>",
            trim((new CarveConverter())->convert($carve)),
        );
    }

    public function testTheBodyKeepsItsBlockContent(): void
    {
        $markdown = implode("\n", [
            '> [!WARNING]',
            '>',
            '> First paragraph.',
            '>',
            '> - one',
            '> - two',
            '>',
            '> ```js',
            '> const x = 1',
            '> ```',
        ]);
        $carve = $this->imported($markdown);

        $this->assertSame("::: warning\nFirst paragraph.\n\n- one\n- two\n\n```js\nconst x = 1\n```\n:::", $carve);
        $html = (new CarveConverter())->convert($carve);
        $this->assertStringContainsString('<aside class="admonition warning" aria-label="Warning">', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString("<code class=\"language-js\">const x = 1\n</code>", $html);
    }

    public function testAQuoteInsideTheBodyStaysAQuote(): void
    {
        $this->assertSame(
            "::: note\nouter\n\n> [!TIP]\n> inner\n:::",
            $this->imported("> [!NOTE]\n> outer\n>\n> > [!TIP]\n> > inner"),
        );
    }

    public function testALazyContinuationLineJoinsTheBody(): void
    {
        $this->assertSame("::: note\nlazy\n:::", $this->imported("> [!NOTE]\nlazy"));
    }

    public function testALiteralColonFenceInTheBodyStaysEscaped(): void
    {
        $carve = $this->imported("> [!NOTE]\n> ::: tip\n> t\n> :::");

        $this->assertSame("::: note\n\\::: tip\nt\n\\:::\n:::", $carve);
        $this->assertSame([Div::class], $this->blockTypes($carve));
    }

    public function testTheImportIsAWriterFixedPoint(): void
    {
        $carve = (new MarkdownToCarve())->convert("# T\n\n> [!CAUTION]\n> - a\n> - b\n\ntext\n");

        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }

    public function testFrontmatterStaysAheadOfTheAdmonition(): void
    {
        $this->assertSame(
            "---yaml\ntitle: x\n---\n\n::: note\nx\n:::",
            $this->imported("---\ntitle: x\n---\n\n> [!NOTE]\n> x\n"),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staysAQuoteProvider(): array
    {
        return [
            'a lowercase label' => ["> [!note]\n> x"],
            'a mixed-case label' => ["> [!Note]\n> x"],
            'an unknown label' => ["> [!FOO]\n> x"],
            'text after the label' => ["> [!TIP] hi\n> x"],
            'the label not on the first line' => ["> x\n> [!NOTE]"],
            'an escaped label' => ["> \\[!NOTE]\n> x"],
            'an empty body' => ['> [!CAUTION]'],
            'an empty body with blank quote lines' => ["> [!CAUTION]\n>\n>"],
            'a hard break after the label' => ["> [!TIP]  \n> x"],
            'a backslash hard break after the label' => ["> [!TIP]\\\n> x"],
            'a blank quote line before the label' => [">\n> [!NOTE]\n> x"],
        ];
    }

    #[DataProvider('staysAQuoteProvider')]
    public function testTheQuoteStaysAQuote(string $markdown): void
    {
        $carve = $this->imported($markdown);

        $this->assertStringStartsWith('>', $carve);
        $this->assertSame([BlockQuote::class], $this->blockTypes($carve));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nestedProvider(): array
    {
        return [
            'in a list item' => ["- > [!NOTE]\n  > x"],
            'in another quote' => ["> > [!NOTE]\n> > x"],
        ];
    }

    #[DataProvider('nestedProvider')]
    public function testANestedAlertStaysAQuote(string $markdown): void
    {
        $document = CarveConverter::carve()->parse($this->imported($markdown));

        $this->assertFalse($this->containsAdmonition($document));
    }

    public function testTheReportRecordsTheConversionAsNormalized(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("# T\n\n> [!IMPORTANT]\n> Read this.\n");

        $this->assertSame("# T\n\n::: info\nRead this.\n:::\n", $result->value);
        $rows = array_map(static fn (MigrationDiagnostic $row): array => $row->toArray(), $result->diagnostics);
        $codes = array_column($rows, 'code');
        $this->assertNotContains('fidelity-unverified', $codes);
        $this->assertNotContains('markdown-block-quote', $codes);
        $alert = $rows[array_search('markdown-github-alert', $codes, true)];
        $this->assertSame(['info', 'normalized', 'exact', 'line:3'], [$alert['severity'], $alert['fidelity'], $alert['confidence'], $alert['path']]);
        $this->assertContains(['markdown-paragraph', 'line:4'], array_map(static fn (array $row): array => [$row['code'], $row['path']], $rows));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quoteReportProvider(): array
    {
        return [
            'a lowercase label' => ["> [!note]\n> x\n"],
            'a blank quote line before the label' => [">\n> [!NOTE]\n> x\n"],
        ];
    }

    #[DataProvider('quoteReportProvider')]
    public function testAQuoteThatStaysAQuoteReportsAsOne(string $markdown): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $rows = array_map(static fn (MigrationDiagnostic $row): array => [$row->code, $row->path], $result->diagnostics);
        $codes = array_column($rows, 0);

        $this->assertNotContains('markdown-github-alert', $codes);
        $this->assertNotContains('fidelity-unverified', $codes);
        $this->assertContains(['markdown-block-quote', 'line:1'], $rows);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verifiedAlertProvider(): array
    {
        return [
            'a body that is only a lazy line' => ["> [!NOTE]\nlazy\n", "::: note\nlazy\n:::\n"],
            'two spaces after the marker' => [">  [!NOTE]\n> x\n", "::: note\nx\n:::\n"],
        ];
    }

    #[DataProvider('verifiedAlertProvider')]
    public function testTheAlertReportIsVerified(string $markdown, string $carve): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $rows = array_map(static fn (MigrationDiagnostic $row): array => [$row->code, $row->fidelity, $row->path], $result->diagnostics);

        $this->assertSame($carve, $result->value);
        $this->assertNotContains('fidelity-unverified', array_column($rows, 0));
        $this->assertContains(['markdown-github-alert', 'normalized', 'line:1'], $rows);
        $this->assertContains(['markdown-paragraph', 'preserved', 'line:2'], $rows);
    }
}
