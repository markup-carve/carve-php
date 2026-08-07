<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer SPELLS THE FRONTMATTER FORMAT TOKEN OUT, `yaml`
 * included: `---yaml`, never a bare `---` (PART 11 §6b, ruled in
 * markup-carve/carve#961 and written in markup-carve/carve#977).
 *
 * The grammar uses the word "canonical" for that exact string, and this is the
 * canonical writer. `yaml` used to be the one format written as a bare `---`,
 * which made the DEFAULT format the single case where the writer did not say
 * what it had parsed - `---toml` and `---json` were already spelled out by all
 * three engines. carve-rs already wrote `---yaml`; carve-js and carve-php move.
 */
class FrontmatterOpenerSpellsItsFormatTest extends TestCase
{
    private function fmt(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    public function testABareOpenerIsWrittenWithItsDefaultFormat(): void
    {
        $this->assertSame("---yaml\ntitle: T\n---\n\nbody\n", $this->fmt("---\ntitle: T\n---\n\nbody\n"));
    }

    public function testASpacedYamlOpenerIsWrittenWithoutTheSpace(): void
    {
        $this->assertSame("---yaml\ntitle: T\n---\n\nbody\n", $this->fmt("--- yaml\ntitle: T\n---\n\nbody\n"));
    }

    public function testAnAlreadyCanonicalYamlOpenerIsUnchanged(): void
    {
        $this->assertSame("---yaml\ntitle: T\n---\n\nbody\n", $this->fmt("---yaml\ntitle: T\n---\n\nbody\n"));
    }

    /**
     * The non-default formats were already written out, and stay written out:
     * the change removes a special case rather than adding one.
     */
    public function testTheOtherFormatsAreUnchanged(): void
    {
        $this->assertSame("---toml\na = 1\n---\n\nbody\n", $this->fmt("--- toml\na = 1\n---\n\nbody\n"));
        $this->assertSame("---json\n{}\n---\n\nbody\n", $this->fmt("--- json\n{}\n---\n\nbody\n"));
    }

    /**
     * PART 11 §1: the canonical form re-parses to the same document and
     * formatting it again changes nothing.
     */
    public function testTheWrittenOpenerRoundTripsAndIsIdempotent(): void
    {
        foreach (["---\ntitle: T\n---\n\nbody\n", "--- yaml\ntitle: T\n---\n\nbody\n", "---toml\na = 1\n---\n\nbody\n"] as $source) {
            $once = $this->fmt($source);
            $this->assertSame($once, $this->fmt($once), 'not idempotent: ' . $source);
            $this->assertSame(
                (new CarveConverter())->convert($source),
                (new CarveConverter())->convert($once),
                'html not preserved: ' . $source,
            );
        }
    }

    /**
     * A bare `---` is still LEGAL INPUT and still parses as yaml frontmatter.
     * Only what the writer emits moves.
     */
    public function testABareOpenerIsStillReadAsYamlFrontmatter(): void
    {
        $this->assertStringNotContainsString('<hr', (new CarveConverter())->convert("---\ntitle: T\n---\n\nbody\n"));
    }
}
