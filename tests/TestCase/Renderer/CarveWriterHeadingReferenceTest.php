<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer reproduces a reference RESOLVED FROM A HEADING as the
 * reference the author wrote (PART 11 R1, carve#478).
 *
 * There is no `[label]: url` line for one, so `[getting started][]` is the only
 * record of the authored form. Writing the resolved link instead bakes a
 * GENERATED id into the source, and does it again on every `fmt` pass - which
 * also made this engine the last one disagreeing with carve-js and carve-rs on
 * the canonical target for corpus 173.
 *
 * An EXPLICIT definition is the other way round on purpose: the writer drops
 * the definition line either way, so the authored pair is not reproducible from
 * the tree, and all three engines write the resolved link there.
 */
class CarveWriterHeadingReferenceTest extends TestCase
{
    private function format(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    public function testAHeadingDerivedReferenceKeepsItsAuthoredForm(): void
    {
        $source = "# Getting Started\n\nSee [getting started][].\n";

        $this->assertSame($source, $this->format($source));
    }

    public function testAnExplicitDefinitionIsWrittenAsAResolvedLink(): void
    {
        $formatted = $this->format("[Defined]: /wins\n\nSee [Defined][].\n");

        $this->assertStringContainsString('[Defined](/wins)', $formatted);
        $this->assertStringNotContainsString('[Defined][]', $formatted);
    }

    /**
     * The id is GENERATED, so the resolved form is not merely a different
     * spelling of the same document - it hard-codes a value that changes when
     * the heading is renamed or when another heading collides with it.
     */
    public function testTheGeneratedIdDoesNotReachTheSource(): void
    {
        $formatted = $this->format("# Getting Started\n\nSee [getting started][].\n");

        $this->assertStringNotContainsString('#Getting-Started', $formatted);
    }

    public function testAnExplicitLabelKeepsThatLabel(): void
    {
        $source = "# Getting Started\n\nSee [the guide][Getting Started].\n";

        $this->assertSame($source, $this->format($source));
    }

    /**
     * Formatting is idempotent, which is the property the bug actually broke:
     * one pass turned the reference into a link, and every later pass kept it.
     */
    public function testFormattingAHeadingReferenceIsIdempotent(): void
    {
        $once = $this->format("# Getting Started\n\nSee [getting started][].\n");

        $this->assertSame($once, $this->format($once));
    }
}
