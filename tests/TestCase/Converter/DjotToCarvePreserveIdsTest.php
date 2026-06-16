<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Converter;

use Carve\CarveConverter;
use Carve\Converter\DjotToCarve;
use Carve\Converter\HeadingId\HtmlHeadingIds;
use Carve\Converter\HeadingId\MapIds;
use Carve\Converter\HeadingId\RenderedHtmlIds;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * preserveHeadingIds(): pin a published Djot document's heading ids that Carve
 * would generate differently, so inbound links survive the migration.
 */
class DjotToCarvePreserveIdsTest extends TestCase
{
    /**
     * Heading ids Carve actually renders for the given Carve source.
     *
     * @return array<int, string>
     */
    private function carveIds(string $carve): array
    {
        return HtmlHeadingIds::extract((new CarveConverter())->convert($carve));
    }

    public function testInjectsDivergentIdAboveHeading(): void
    {
        $djot = "# Hello World\n\nText.";
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['legacy-hello']))
            ->convert($djot);

        $this->assertSame("{#legacy-hello}\n# Hello World\n\nText.", $out);
        // The migrated Carve renders the preserved id.
        $this->assertContains('legacy-hello', $this->carveIds($out));
    }

    public function testDoesNotInjectWhenIdAlreadyMatches(): void
    {
        $djot = "# Hello World\n\nText.";
        // Feed back the exact ids Carve already generates -> nothing to pin.
        $liveIds = $this->carveIds($djot);
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds($liveIds))
            ->convert($djot);

        $this->assertStringNotContainsString('{#', $out);
    }

    public function testScrapesIdsFromPublishedHtmlSectionWrapper(): void
    {
        // Carve and Djot both render <section id="..."><hN>…</hN></section>.
        $published = '<section id="Hello-World"><h1>Hello World</h1></section>'
            . '<section id="API-Reference"><h2>API Reference</h2></section>';
        $djot = "# Hello World\n\n## API Reference\n";

        $out = (new DjotToCarve())
            ->preserveHeadingIds(new RenderedHtmlIds($published))
            ->convert($djot);

        $this->assertStringContainsString("{#Hello-World}\n# Hello World", $out);
        $this->assertStringContainsString("{#API-Reference}\n## API Reference", $out);
    }

    public function testScrapesIdFromHeadingElementWhenNotSectionWrapped(): void
    {
        // Flat / GitHub-style: the id is on the heading itself.
        $published = '<h1 id="flat-id">Hello World</h1>';
        $ids = HtmlHeadingIds::extract($published);
        $this->assertSame(['flat-id'], $ids);
    }

    public function testIgnoresPermalinkAnchorChild(): void
    {
        $published = '<section id="sec"><h1><a href="#sec" class="permalink"></a>Title</h1></section>';
        $this->assertSame(['sec'], HtmlHeadingIds::extract($published));
    }

    public function testDoesNotDoubleInjectWhenAlreadyPinned(): void
    {
        // Heading already carries an explicit block-attribute id.
        $djot = "{#manual}\n# Hello World\n";
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['something-else']))
            ->convert($djot);

        // No second {#...} line injected above the existing one.
        $this->assertSame(1, substr_count($out, '{#'));
        $this->assertStringContainsString('{#manual}', $out);
    }

    public function testPreservesCollisionSuffixedIds(): void
    {
        $djot = "# Setup\n\n## Setup\n";
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['Setup', 'Setup-2']))
            ->convert($djot);

        $ids = $this->carveIds($out);
        $this->assertContains('Setup', $ids);
        $this->assertContains('Setup-2', $ids);
    }

    public function testEmptyLiveIdLeavesHeadingUntouched(): void
    {
        $djot = "# Hello World\n";
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['']))
            ->convert($djot);

        $this->assertStringNotContainsString('{#', $out);
    }

    public function testThrowsOnHeadingCountMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $djot = "# One\n\n# Two\n";
        (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['only-one']))
            ->convert($djot);
    }

    public function testDisabledByDefault(): void
    {
        $djot = "# Hello World\n";
        $this->assertStringNotContainsString('{#', (new DjotToCarve())->convert($djot));
    }

    public function testStillMigratesDelimitersWhilePreservingIds(): void
    {
        // Delimiter migration (**bold** -> *bold*) and id injection compose.
        $djot = "# Hello\n\nThis is **bold**.";
        $out = (new DjotToCarve())
            ->preserveHeadingIds(new MapIds(['legacy']))
            ->convert($djot);

        $this->assertStringContainsString('{#legacy}', $out);
        $this->assertStringContainsString('*bold*', $out);
        $this->assertStringNotContainsString('**bold**', $out);
    }

    public function testIdempotentOnSecondRun(): void
    {
        $djot = "# Hello World\n";
        $once = (new DjotToCarve())->preserveHeadingIds(new MapIds(['legacy']))->convert($djot);
        // Re-running over already-pinned output injects nothing more.
        $twice = (new DjotToCarve())->preserveHeadingIds(new MapIds(['legacy']))->convert($once);
        $this->assertSame(1, substr_count($twice, '{#legacy}'));
    }
}
