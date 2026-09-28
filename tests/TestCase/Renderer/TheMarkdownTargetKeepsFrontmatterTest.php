<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10r (markup-carve/carve#2501): the Markdown target emits frontmatter
 * first, with the format token wherever the format is not `yaml`, and the
 * content verbatim. HTML, plain text and the terminal keep omitting it.
 *
 * The importer already preserved frontmatter, so dropping it here made a
 * Markdown import lossy on the way back out to Markdown - the one direction
 * where both ends spell the construct natively.
 */
class TheMarkdownTargetKeepsFrontmatterTest extends TestCase
{
    private CarveConverter $converter;

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new MarkdownRenderer();
    }

    private function render(string $source): string
    {
        return $this->renderer->render($this->converter->parse($source));
    }

    public function testFrontmatterIsEmittedFirstWithABareOpenerForYaml(): void
    {
        $this->assertSame(
            "---\ntitle: Hi\n---\n\n# H\n\ntext\n",
            $this->render("---\ntitle: Hi\n---\n\n# H\n\ntext\n"),
        );
    }

    public function testAFormatThatIsNotYamlKeepsItsToken(): void
    {
        $this->assertSame("---toml\nt = 1\n---\n\nx\n", $this->render("---toml\nt = 1\n---\n\nx\n"));
    }

    public function testASpacedYamlOpenerIsSpelledBare(): void
    {
        $this->assertSame("---\na: 1\n---\n\nx\n", $this->render("--- yaml\na: 1\n---\n\nx\n"));
    }

    public function testADocumentThatIsOnlyFrontmatterNeedsNoBlankLineAfterIt(): void
    {
        $this->assertSame("---\na: 1\n---\n", $this->render("---\na: 1\n---\n"));
    }

    public function testTheContentIsVerbatimBlankLinesAndMetacharactersIncluded(): void
    {
        $source = "---\nlist:\n\n\n  - \"*not emphasis*\"\n---\n\nx\n";
        $this->assertSame($source, $this->render($source));
    }

    public function testTheEmittedBlockIsAFixedPoint(): void
    {
        $once = $this->render("---toml\nt = 1\n---\n\nx\n");
        $this->assertSame($once, $this->render($once));
    }

    public function testATrojanSourceControlGoesLikeEveryOtherByte(): void
    {
        $this->assertSame("---\na: b\n---\n\nx\n", $this->render("---\na: \u{202e}b\n---\n\nx\n"));
    }

    public function testTheOtherThreeTargetsStillOmitIt(): void
    {
        $source = "---\ntitle: Hi\n---\n\ntext\n";
        $document = $this->converter->parse($source);

        $this->assertStringNotContainsString('title', $this->converter->convert($source));
        $this->assertSame("text\n", (new PlainTextRenderer())->render($document));
        $this->assertSame("text\n", (new AnsiRenderer())->render($this->converter->parse($source)));
    }
}
