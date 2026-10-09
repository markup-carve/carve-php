<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A FRONTMATTER OPENER IS WRITTEN `---yaml` (CARVE-P11-011, PART 11 section 6b):
 * the canonical writer spells the format token for EVERY format, the default one
 * included.
 *
 * The importer used to emit the source opener verbatim, so a bare `---` in the
 * Markdown came back as a bare `---` while this engine's own `fmt` wrote
 * `---yaml` on the same document. A READER'S LENIENCY IS NOT A WRITER'S LICENSE,
 * and the cost was user-visible: a freshly imported file failed the repo's own
 * `fmt --check` gate on a diff the author never introduced.
 *
 * The opener now comes from the canonical writer's own spelling helper, so the
 * token cannot be hard-coded into a second place and drift.
 */
class TheMarkdownImporterWritesACanonicalFrontmatterOpenerTest extends TestCase
{
    private function convert(string $markdown): string
    {
        return (new MarkdownToCarve())->convert($markdown);
    }

    private function fmt(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    public function testSpellsTheDefaultFormatOnABareOpener(): void
    {
        $this->assertSame(
            "---yaml\ntitle: Hi\n---\n\nBody.\n",
            $this->convert("---\ntitle: Hi\n---\n\nBody.\n"),
        );
    }

    /**
     * Section 6b covers every format rather than the default alone. An
     * implementation that pasted the string `---yaml` in would satisfy the test
     * above and fail this one.
     */
    public function testSpellsATypedFormatSoAHardCodedYamlWouldNotPass(): void
    {
        $this->assertSame(
            "---toml\ntitle = \"Hi\"\n---\n\nBody.\n",
            $this->convert("---toml\ntitle = \"Hi\"\n---\n\nBody.\n"),
        );
    }

    public function testCanonicalizesTheLenientSpacedSpellingOfEitherFormat(): void
    {
        $this->assertSame(
            "---toml\ntitle = \"Hi\"\n---\n\nBody.\n",
            $this->convert("--- toml\ntitle = \"Hi\"\n---\n\nBody.\n"),
        );
        $this->assertSame(
            "---yaml\ntitle: Hi\n---\n\nBody.\n",
            $this->convert("--- yaml\ntitle: Hi\n---\n\nBody.\n"),
        );
    }

    /**
     * The diagnostic in the ticket: the two writers in one engine disagreed.
     */
    public function testAgreesWithWhatFmtWritesForTheSameDocument(): void
    {
        $cases = [
            "---\ntitle: Hi\n---\n\nBody.\n",
            "---toml\ntitle = \"Hi\"\n---\n\nBody.\n",
            "--- toml\ntitle = \"Hi\"\n---\n\nBody.\n",
        ];
        foreach ($cases as $markdown) {
            $this->assertSame($this->fmt($markdown), $this->convert($markdown), 'writers disagree on: ' . $markdown);
        }
    }

    public function testLeavesAnImportedDocumentWithNothingForFmtCheckToReport(): void
    {
        $cases = [
            "---\ntitle: Hi\n---\n\nBody.\n",
            "---toml\ntitle = \"Hi\"\n---\n\nBody.\n",
            "--- yaml\ntitle: a **bold** value\n---\n\nBody.\n",
        ];
        foreach ($cases as $markdown) {
            $imported = $this->convert($markdown);
            $this->assertSame($imported, $this->fmt($imported), 'not a writer fixed point: ' . $markdown);
        }
    }

    /**
     * Only the opener is the canonical writer's to spell. The content is opaque
     * and a YAML value is data, not prose.
     */
    public function testControlTheMetadataBetweenTheFencesStillSurvivesByteForByte(): void
    {
        $imported = $this->convert("---\ntitle: a **bold** and _under_ value\n---\n\nBody.\n");

        $this->assertStringContainsString("\ntitle: a **bold** and _under_ value\n", $imported);
    }

    /**
     * `frontmatter_close` names no format slot, so only the opener takes a token.
     */
    public function testControlTheCloserStaysBare(): void
    {
        $lines = explode("\n", $this->convert("---\ntitle: Hi\n---\n\nBody.\n"));

        $this->assertSame('---', $lines[2]);
    }

    /**
     * Detection is out of scope here; this guards that the opener rewrite did
     * not start manufacturing frontmatter where there was none.
     */
    public function testControlAnEmptyFencePairIsStillTwoThematicBreaks(): void
    {
        $html = (new CarveConverter())->convert($this->convert("---\n---\n"));

        $this->assertSame("<hr>\n<hr>\n", $html);
    }
}
