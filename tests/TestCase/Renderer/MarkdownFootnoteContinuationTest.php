<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownFootnoteContinuationTest extends TestCase
{
    public function testSecondParagraphStaysInTheFootnote(): void
    {
        $this->assertRoundTrip(
            "see[^a]\n\n[^a]: note\n\n  second para\n",
            "see[^a]\n\n[^a]: note\n\n    second para\n",
            ['paragraph', 'paragraph'],
        );
    }

    public function testListAndFencedCodeKeepTheirLayout(): void
    {
        $this->assertRoundTrip(
            "see[^a]\n\n[^a]: note\n\n  - one\n    - nested\n\n  ```php\n  echo 1;\n\n  echo 2;\n  ```\n",
            "see[^a]\n\n[^a]: note\n\n    - one\n      - nested\n\n    ```php\n    echo 1;\n\n    echo 2;\n    ```\n",
            ['paragraph', 'list', 'code_block'],
        );
    }

    public function testContinuationKeepsDocumentReferences(): void
    {
        $markdown = "see[^a]\n\n[^a]: note\n\n    [link][target]\n\n[target]: https://example.com\n";
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertStringContainsString('<a href="https://example.com">link</a>', (new CarveConverter())->convert($imported));
    }

    public function testThematicBreaksDoNotOpenFrontmatterInTheBody(): void
    {
        $this->assertRoundTrip(
            "see[^a]\n\n[^a]: ---\n\n  title: x\n\n  ---\n\n  tail\n",
            "see[^a]\n\n[^a]: ---\n\n    title: x\n\n    ---\n\n    tail\n",
            ['thematic_break', 'paragraph', 'thematic_break', 'paragraph'],
        );
    }

    public function testBodyDiagnosticsKeepTheirSourceLines(): void
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport("see[^a]\n\n[^a]: note\n\n    | a | b |\n    |---|---|\n    | 1 | 2 | 3 |\n");
        $diagnostics = array_filter($report->diagnostics, fn ($diagnostic): bool => $diagnostic->code === 'markdown-table-extra-cells');
        $this->assertCount(1, $diagnostics);
        $this->assertSame('line:7', array_values($diagnostics)[0]->path);

        $report = (new MarkdownToCarve())->convertWithFidelityReport("see[^a]\n\n[^a]: note\n\n    1. [x] done\n");
        $diagnostics = array_filter($report->diagnostics, fn ($diagnostic): bool => $diagnostic->code === 'structure-unspellable');
        $this->assertCount(1, $diagnostics);
        $this->assertSame('line:5', array_values($diagnostics)[0]->path);
    }

    public function testEmptyOpeningLineHasNoTrailingWhitespace(): void
    {
        $imported = (new MarkdownToCarve())->convert("see[^a]\n\n[^a]:\n\n    second\n");

        $this->assertSame("see[^a]\n\n[^a]: {empty}\n\n  second\n", $imported);
        $this->assertStringContainsString('<li id="fn1">', (new CarveConverter())->convert($imported));
    }

    /**
     * @param string $source
     * @param string $expected
     * @param list<string> $blockTypes
     */
    private function assertRoundTrip(string $source, string $expected, array $blockTypes): void
    {
        $converter = new CarveConverter();
        $renderer = new MarkdownRenderer();
        $document = $converter->parse($source);
        $markdown = $renderer->render($document);
        $this->assertSame($expected, $markdown);
        $imported = $converter->parse((new MarkdownToCarve())->convert($markdown));
        $this->assertSame(['paragraph', 'footnote'], array_map(fn (Node $node): string => $node->getType(), $imported->getChildren()));
        $footnote = $imported->getChildren()[1];
        $this->assertInstanceOf(Footnote::class, $footnote);
        $this->assertSame($blockTypes, array_map(fn (Node $node): string => $node->getType(), $footnote->getChildren()));
        $this->assertSame($converter->convert($source), $converter->convert((new MarkdownToCarve())->convert($markdown)));
        $this->assertSame($expected, $renderer->render($imported));
    }
}
