<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ReferenceLabelWhitespaceKeyTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function resolves(string $source): bool
    {
        return str_contains($this->html($source), 'href="/u"');
    }

    public function testDifferingWhitespaceInTheReferenceResolves(): void
    {
        $this->assertTrue($this->resolves("see [t][ b  c]\n\n[b c]: /u\n"));
    }

    public function testDifferingWhitespaceInTheDefinitionResolves(): void
    {
        $this->assertTrue($this->resolves("see [t][b c]\n\n[ b  c]: /u\n"));
    }

    public function testIdenticalPaddingDoesResolve(): void
    {
        $this->assertTrue($this->resolves("see [t][b ]\n\n[b ]: /u\n"));
        $this->assertTrue($this->resolves("see [t][ b]\n\n[ b]: /u\n"));
    }

    public function testIdenticalDoubledInternalSpacingDoesResolve(): void
    {
        $this->assertTrue($this->resolves("see [t][b  c]\n\n[b  c]: /u\n"));
    }

    public function testTheCollapsedFormIsExactToo(): void
    {
        $this->assertTrue($this->resolves("see [ b  c][]\n\n[b c]: /u\n"));
        $this->assertTrue($this->resolves("see [ b  c][]\n\n[ b  c]: /u\n"));
    }

    public function testTheOrdinaryCaseStillResolves(): void
    {
        $this->assertTrue($this->resolves("see [t][b c]\n\n[b c]: /u\n"));
        $this->assertTrue($this->resolves("see [b c][]\n\n[b c]: /u\n"));
    }

    public function testCaseIsStillNotNormalized(): void
    {
        // The half that was always right.
        $this->assertFalse($this->resolves("see [t][BAR]\n\n[bar]: /u\n"));
    }

    public function testAnImplicitHeadingReferenceStillFoldsWhitespaceAndCase(): void
    {
        // The fuzzy path, deliberately unchanged: all four implementations match
        // `[my heading][]` against `# My  Heading`.
        $html = $this->html("# My  Heading\n\nsee [my heading][]\n");

        $this->assertStringContainsString('href="#My-Heading"', $html);
    }
}
