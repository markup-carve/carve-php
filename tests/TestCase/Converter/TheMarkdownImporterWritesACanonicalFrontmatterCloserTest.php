<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FRONTMATTER CLOSER IS WRITTEN BARE (PART 1, PART 11 section 6b): a reader
 * drops the trailing run of spaces and tabs, so `---<TAB>` closes the block,
 * but the writer spells the delimiter `---` and nothing else.
 *
 * The importer used to echo the source closer verbatim, so a Markdown document
 * closing its frontmatter with `---<SP><TAB>` imported to `---<SP><TAB>` while
 * this engine's own `fmt` wrote `---`. A READER'S LENIENCY IS NOT A WRITER'S
 * LICENSE, and the cost was user-visible: a repo gating on `carve fmt --check`
 * failed on its own migration output (carve-php#2998).
 *
 * The closer now comes from the writer's own constant, so the delimiter cannot
 * be hard-coded into a second place and drift - the same cure as the opener in
 * the sibling test and as the block separator in carve-php#2997.
 */
class TheMarkdownImporterWritesACanonicalFrontmatterCloserTest extends TestCase
{
    private function convert(string $markdown): string
    {
        return (new MarkdownToCarve())->convert($markdown);
    }

    private function fmt(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    /**
     * Every spelling PART 1 accepts for the closer, under both a typed and a
     * bare opener. A fix that handled the tab alone would pass one of these.
     *
     * @return array<string, array<int, string>>
     */
    public static function closerProvider(): array
    {
        $cases = [];
        foreach (['bare' => '---', 'space' => '--- ', 'tab' => "---\t", 'space-tab' => "--- \t", 'tabs' => "---\t\t"] as $name => $closer) {
            $cases['typed opener, ' . $name . ' closer'] = ["---yaml\ntitle: Hi\n" . $closer . "\nBody\n", "---yaml\ntitle: Hi\n---\n\nBody\n"];
            $cases['bare opener, ' . $name . ' closer'] = ["---\ntitle: Hi\n" . $closer . "\nBody\n", "---yaml\ntitle: Hi\n---\n\nBody\n"];
        }

        return $cases;
    }

    #[DataProvider('closerProvider')]
    public function testWritesTheCloserBareWhateverTheSourceSpelled(string $markdown, string $expected): void
    {
        $this->assertSame($expected, $this->convert($markdown));
    }

    /**
     * The assertion the ticket makes: the round trip, not the bytes alone.
     */
    #[DataProvider('closerProvider')]
    public function testLeavesAnImportedDocumentWithNothingForFmtCheckToReport(string $markdown, string $expected): void
    {
        $imported = $this->convert($markdown);

        $this->assertSame($imported, $this->fmt($imported), 'not a writer fixed point: ' . $markdown);
        $this->assertSame($expected, $imported);
    }

    /**
     * The diagnostic in the ticket: the two writers in one engine disagreed.
     */
    #[DataProvider('closerProvider')]
    public function testAgreesWithWhatFmtWritesForTheSameDocument(string $markdown, string $expected): void
    {
        $this->assertSame($this->fmt($markdown), $this->convert($markdown), 'writers disagree on: ' . $markdown);
        $this->assertSame($expected, $this->fmt($markdown));
    }

    /**
     * CONTROL. The cure is the writer's spelling of ONE delimiter, not a trim:
     * the metadata between the fences is opaque and keeps its own trailing
     * whitespace byte-for-byte. An importer that trimmed every line would pass
     * every assertion above and fail this one.
     */
    public function testControlTrailingWhitespaceInsideTheBlockSurvivesByteForByte(): void
    {
        $imported = $this->convert("---yaml\ntitle: Hi  \nlist:\n  - a\t\n--- \nBody\n");

        $this->assertStringContainsString("\ntitle: Hi  \n", $imported);
        $this->assertStringContainsString("\n  - a\t\n", $imported);
        $this->assertSame("---yaml\ntitle: Hi  \nlist:\n  - a\t\n---\n\nBody\n", $imported);
    }

    /**
     * CONTROL. A `---` run that is NOT a frontmatter closer is a thematic
     * break, and its own spelling is a separate question the writer answers
     * elsewhere. Rewriting the closer must not reach a break in the body.
     */
    public function testControlABreakInTheBodyIsNotTouchedByTheCloserRewrite(): void
    {
        $imported = $this->convert("---yaml\ntitle: Hi\n--- \nBody\n\n*** \n\nMore\n");

        $this->assertSame("---yaml\ntitle: Hi\n---\n\nBody\n\n---\n\nMore\n", $imported);
    }

    /**
     * CONTROL. Detection is out of scope: a document whose closer carries
     * whitespace has frontmatter under PART 1, and the rewrite must not start
     * manufacturing frontmatter where a reader finds none.
     */
    public function testControlAnEmptyFencePairIsStillTwoThematicBreaks(): void
    {
        $html = (new CarveConverter())->convert($this->convert("---\n--- \t\n"));

        $this->assertSame("<hr>\n<hr>\n", $html);
    }

    /**
     * CONTROL. The opener keeps its canonical token; this change is the closer
     * alone.
     */
    public function testControlTheOpenerStillSpellsItsFormat(): void
    {
        $this->assertSame("---toml\ntitle = \"Hi\"\n---\n\nBody\n", $this->convert("--- toml\ntitle = \"Hi\"\n--- \t\nBody\n"));
    }
}
