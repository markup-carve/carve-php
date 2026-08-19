<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the linear cost of `</#id>` cross-reference resolution.
 *
 * Cross-reference targets are matched through HeadingIdTracker. An exact-case
 * target hits the textById fast path; a different-case target is resolved via
 * the idByFoldedId map. Both are O(1) per reference, so a document with many
 * references stays linear in the number of references and in the number of
 * heading targets.
 *
 * The previous implementation case-folded and scanned every known heading id
 * for each case-insensitive reference (O(headings * references)); scaling both
 * together was quadratic. These tests bound the wall-clock time so that
 * regression cannot return unnoticed, and assert the links still resolve.
 */
class CrossReferenceScaleTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testManyReferencesToOneTargetResolveCorrectly(): void
    {
        $source = "{#t}\n# Heading\n\n" . str_repeat('</#t> ', 5);
        $html = $this->converter->convert($source);

        // Every reference renders as an anchor to the target heading.
        $this->assertSame(5, substr_count($html, '<a href="#t">Heading</a>'));
    }

    public function testCaseInsensitiveReferencesResolveCorrectly(): void
    {
        $source = "{#MyTarget}\n# Heading\n\n" . str_repeat('</#mytarget> ', 5);
        $html = $this->converter->convert($source);

        // A lower-case reference resolves to the case-preserved id.
        $this->assertSame(5, substr_count($html, '<a href="#MyTarget">Heading</a>'));
    }

    /**
     * IN THE `scaling` GROUP because it is a WALL-CLOCK measurement. The
     * default suite runs under paratest, one process per core, so a timing test
     * there measures a machine every one of its siblings is loading - and it
     * turned `main` red on a commit touching no engine code (ratio 1.38 against
     * a 1.2 bound). The group has a runner of its own where nothing else is
     * running, which is the condition the measurement needs.
     */
    #[Group('scaling')]
    public function testManyReferencesToOneTargetStayLinear(): void
    {
        $source = "{#t}\n# Heading\n\n" . str_repeat('</#t> ', 32000);

        $start = hrtime(true);
        $html = $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertSame(32000, substr_count($html, '<a href="#t">Heading</a>'));
        // Linear is well under a second; the only realistic way to blow this
        // bound is a super-linear per-reference resolution regression.
        $this->assertLessThan(5.0, $elapsed, "32000 cross-references took {$elapsed}s (super-linear regression?)");
    }

    /**
     * IN THE `scaling` GROUP because it is a WALL-CLOCK measurement. The
     * default suite runs under paratest, one process per core, so a timing test
     * there measures a machine every one of its siblings is loading - and it
     * turned `main` red on a commit touching no engine code (ratio 1.38 against
     * a 1.2 bound). The group has a runner of its own where nothing else is
     * running, which is the condition the measurement needs.
     */
    #[Group('scaling')]
    public function testManyHeadingsAndCaseInsensitiveReferencesStayLinear(): void
    {
        // Scaling distinct heading targets AND case-insensitive references
        // together is the input that exposed the O(headings * references)
        // fold-and-scan. With the folded-id lookup map it is linear.
        $headings = 2000;
        $references = 2000;

        $source = '';
        for ($i = 0; $i < $headings; $i++) {
            $source .= "{#Target{$i}}\n# Heading{$i}\n\n";
        }
        for ($i = 0; $i < $references; $i++) {
            $source .= '</#target' . ($i % $headings) . '> ';
        }

        $start = hrtime(true);
        $html = $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // Each reference resolves to its (case-preserved) target heading.
        $this->assertStringContainsString('<a href="#Target0">Heading0</a>', $html);
        $this->assertStringContainsString('<a href="#Target1">Heading1</a>', $html);
        $this->assertLessThan(5.0, $elapsed, "{$headings} headings x {$references} refs took {$elapsed}s (quadratic regression?)");
    }
}
