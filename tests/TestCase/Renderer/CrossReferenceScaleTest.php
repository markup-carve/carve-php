<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the linear cost of `</#id>` cross-reference resolution.
 *
 * Cross-reference targets are matched exactly through HeadingIdTracker's
 * textById map, O(1) per reference, so a document with many references stays
 * linear in the number of references and in the number of heading targets.
 * These tests bound the wall-clock time so a per-reference scan cannot return
 * unnoticed, and assert the links still resolve.
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

    public function testCaseOnlyMismatchedReferencesStayLiteral(): void
    {
        $source = "{#MyTarget}\n# Heading\n\n" . str_repeat('</#mytarget> ', 5);
        $html = $this->converter->convert($source);

        $this->assertSame(5, substr_count($html, '&lt;/#mytarget&gt;'));
        $this->assertStringNotContainsString('<a href=', $html);
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
    public function testManyHeadingsAndReferencesStayLinear(): void
    {
        // Scaling distinct heading targets AND references together is the
        // input that exposed an O(headings * references) scan.
        $headings = 2000;
        $references = 2000;

        $source = '';
        for ($i = 0; $i < $headings; $i++) {
            $source .= "{#Target{$i}}\n# Heading{$i}\n\n";
        }
        for ($i = 0; $i < $references; $i++) {
            $source .= '</#Target' . ($i % $headings) . '> ';
        }

        $start = hrtime(true);
        $html = $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertStringContainsString('<a href="#Target0">Heading0</a>', $html);
        $this->assertStringContainsString('<a href="#Target1">Heading1</a>', $html);
        $this->assertLessThan(5.0, $elapsed, "{$headings} headings x {$references} refs took {$elapsed}s (quadratic regression?)");
    }
}
