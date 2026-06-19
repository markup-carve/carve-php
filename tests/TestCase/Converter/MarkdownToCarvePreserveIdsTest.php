<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Converter;

use Carve\CarveConverter;
use Carve\Converter\HeadingId\HtmlHeadingIds;
use Carve\Converter\HeadingId\MapIds;
use Carve\Converter\HeadingId\RenderedHtmlIds;
use Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * preserveHeadingIds() on the Markdown migrator: a published Markdown doc
 * (GitHub/kramdown/pandoc each slug differently from Carve) keeps its heading
 * anchors on import.
 */
class MarkdownToCarvePreserveIdsTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function carveIds(string $carve): array
    {
        return HtmlHeadingIds::extract((new CarveConverter())->convert($carve));
    }

    public function testInjectsDivergentIdAboveHeading(): void
    {
        $markdown = "# Hello World\n";
        $out = (new MarkdownToCarve())
            ->preserveHeadingIds(new MapIds(['legacy-hello']))
            ->convert($markdown);

        $this->assertStringContainsString("{#legacy-hello}\n# Hello World", $out);
        $this->assertContains('legacy-hello', $this->carveIds($out));
    }

    public function testScrapesGitHubStyleHeadingAnchors(): void
    {
        // A published GitHub page renders the id on the heading element itself.
        $published = '<h1 id="hello-world">Hello World</h1><h2 id="api">API</h2>';
        $markdown = "# Hello World\n\n## API\n";
        $out = (new MarkdownToCarve())
            ->preserveHeadingIds(new RenderedHtmlIds($published))
            ->convert($markdown);

        $ids = $this->carveIds($out);
        $this->assertContains('hello-world', $ids);
        $this->assertContains('api', $ids);
    }

    public function testNoInjectionWhenIdsAlreadyMatch(): void
    {
        $markdown = "# Hello World\n";
        $liveIds = $this->carveIds((new MarkdownToCarve())->convert($markdown));
        $out = (new MarkdownToCarve())
            ->preserveHeadingIds(new MapIds($liveIds))
            ->convert($markdown);

        $this->assertStringNotContainsString('{#', $out);
    }

    public function testPreservesIdsWhileMigratingMarkdownSyntax(): void
    {
        $markdown = "# Hello\n\nThis is **bold**.\n";
        $out = (new MarkdownToCarve())
            ->preserveHeadingIds(new MapIds(['legacy']))
            ->convert($markdown);

        $this->assertStringContainsString('{#legacy}', $out);
        $this->assertStringContainsString('*bold*', $out);
        $this->assertStringNotContainsString('**bold**', $out);
    }

    public function testThrowsOnHeadingCountMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        (new MarkdownToCarve())
            ->preserveHeadingIds(new MapIds(['only-one']))
            ->convert("# One\n\n# Two\n");
    }

    public function testDisabledByDefault(): void
    {
        $this->assertStringNotContainsString('{#', (new MarkdownToCarve())->convert("# Hello World\n"));
    }

    public function testIgnoresHashInFencedCode(): void
    {
        // A `#` line inside a code fence must not be counted as a heading.
        $markdown = "# Real Heading\n\n```\n# not a heading\n```\n";
        $out = (new MarkdownToCarve())
            ->preserveHeadingIds(new MapIds(['legacy']))
            ->convert($markdown);

        $this->assertStringContainsString("{#legacy}\n# Real Heading", $out);
    }
}
