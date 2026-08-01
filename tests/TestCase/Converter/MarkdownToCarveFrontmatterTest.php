<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Leading frontmatter on the Markdown migrator.
 *
 * Frontmatter is opaque metadata in Markdown and in Carve alike - both strip it
 * before block parsing - so it has to survive the migration byte-for-byte. Left
 * to the normal line transform, the opening `---` reads as a thematic break and
 * the closing one as a setext underline, so `description: y` becomes an `##`
 * heading and the metadata is destroyed.
 *
 * Behavior mirrors carve-js, the reference engine (carve-js#487).
 */
class MarkdownToCarveFrontmatterTest extends TestCase
{
    private function convert(string $markdown): string
    {
        return (new MarkdownToCarve())->convert($markdown);
    }

    private function html(string $markdown): string
    {
        return (new CarveConverter())->convert($this->convert($markdown));
    }

    public function testPreservesFrontmatterVerbatimAndConvertsOnlyTheBody(): void
    {
        $markdown = "---\ntitle: X\ndescription: Y\n---\n\n# H\n\na **bold** word\n";
        $this->assertSame(
            "---\ntitle: X\ndescription: Y\n---\n\n# H\n\na *bold* word\n",
            $this->convert($markdown),
        );
    }

    public function testDoesNotRewriteMarkdownDelimitersInsideFrontmatter(): void
    {
        $markdown = "---\ntitle: a **bold** and _under_ value\n---\n\na **bold** word\n";
        $this->assertSame(
            "---\ntitle: a **bold** and _under_ value\n---\n\na *bold* word\n",
            $this->convert($markdown),
        );
    }

    public function testPreservesAFormatLabeledFence(): void
    {
        $markdown = "---toml\ntitle = \"X\"\n---\n\ntext\n";
        $this->assertSame("---toml\ntitle = \"X\"\n---\n\ntext\n", $this->convert($markdown));
    }

    public function testPreservesTheLenientSpacedFormatLabel(): void
    {
        $markdown = "--- toml\ntitle = \"X\"\n---\n\na **bold** word\n";
        $this->assertSame(
            "--- toml\ntitle = \"X\"\n---\n\na *bold* word\n",
            $this->convert($markdown),
        );
    }

    public function testKeepsFrontmatterOutOfTheRenderedBody(): void
    {
        $this->assertSame("<p>text</p>\n", $this->html("---\ntitle: X\n---\n\ntext\n"));
    }

    public function testHandlesAFrontmatterOnlyDocument(): void
    {
        $this->assertSame("---\ntitle: X\n---", $this->convert("---\ntitle: X\n---"));
    }

    public function testAnUnclosedLeadingFenceStaysAThematicBreak(): void
    {
        // No closing fence, so Carve would not read frontmatter either.
        $this->assertSame("<hr>\n<p>text</p>\n", $this->html("---\n\ntext\n"));
    }

    public function testAnEmptyFencePairStaysTwoThematicBreaks(): void
    {
        // An empty fence carries no metadata, so the CommonMark reading - two
        // rules - is the meaning-preserving one.
        $this->assertSame("<hr>\n<hr>\n", $this->html("---\n---\n"));
        $this->assertSame("<hr>\n<hr>\n", $this->html("---\n\n---\n"));
    }

    public function testARuleLineIsNotConsumedAsSetextHeadingText(): void
    {
        // CommonMark: `***\n---` is two thematic breaks, not an h2 titled `***`.
        $this->assertSame("<hr>\n<hr>\n", $this->html("***\n---\n"));
    }

    public function testADocumentOpeningWithARuleDoesNotVanishIntoFrontmatter(): void
    {
        $this->assertSame("<hr>\n<hr>\n", $this->html("***\n\n***\n"));
        $this->assertSame("<hr>\n<p>text</p>\n<hr>\n", $this->html("***\n\ntext\n\n***\n"));
    }
}
