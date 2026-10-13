<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §13a presence carriers for a bare div, a line block and a
 * substitution: written under roundtrip export only, read in every import mode.
 */
class ABareDivALineBlockAndASubstitutionAreCarriedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCarried(): array
    {
        return [
            'bare div' => [":::\ninside\n:::\n", '<div data-carve-div>'],
            'line block' => ["::: |\nfirst\nsecond\n:::\n", '<div data-carve-line-block class="line-block">'],
            'line block with an id' => ["{#i}\n::: |\na\n:::\n", '<div data-carve-line-block id="i" class="line-block">'],
            'substitution' => ["a {~old~>new~} b\n", '<span data-carve-substitution><del>old</del><ins>new</ins></span>'],
        ];
    }

    #[DataProvider('provideCarried')]
    public function testCarriesUnderRoundtripExportOnly(string $source, string $carried): void
    {
        $this->assertStringContainsString($carried, (new CarveConverter(roundTripMode: true))->convert($source));
        $this->assertStringNotContainsString('data-carve-', (new CarveConverter())->convert($source));
    }

    public function testWritesNoDivCarrierWhereTheDivHasAnotherAttribute(): void
    {
        $html = (new CarveConverter(roundTripMode: true))->convert("{.x}\n:::\ninside\n:::\n");

        $this->assertSame("<div class=\"x\">\n  <p>inside</p>\n</div>", rtrim($html, "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideReads(): array
    {
        $cases = [];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $cases[$mode . ' div'] = [$mode, '<div data-carve-div><p>x</p></div>', ":::\nx\n:::\n"];
            $cases[$mode . ' legacy div'] = [$mode, '<div data-djot-div><p>x</p></div>', ":::\nx\n:::\n"];
            $cases[$mode . ' line block'] = [$mode, "<div data-carve-line-block class=\"line-block\"><p>a<br>\nb</p></div>", "::: |\na\nb\n:::\n"];
            $cases[$mode . ' substitution'] = [$mode, '<p><span data-carve-substitution><del>o</del><ins>n</ins></span></p>', "{~o~>n~}\n"];
        }

        return $cases;
    }

    #[DataProvider('provideReads')]
    public function testRebuildsEachNodeInEveryMode(string $mode, string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve(importMode: $mode))->convert($html));
    }

    public function testReadsABareDivWithoutTheCarrierAsItsContent(): void
    {
        $this->assertSame("x\n", (new HtmlToCarve())->convert('<div><p>x</p></div>'));
    }

    public function testReadsTheDivCarrierOverAContainerClass(): void
    {
        $this->assertSame("{.note}\n:::\nx\n:::\n", (new HtmlToCarve())->convert('<div data-carve-div class="note"><p>x</p></div>'));
    }

    public function testNeverReadsALineBlockFromItsClassAlone(): void
    {
        $this->assertSame("::: line-block\na\\\nb\n:::\n", (new HtmlToCarve())->convert("<div class=\"line-block\"><p>a<br>\nb</p></div>"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNoSubstitution(): array
    {
        return [
            'another attribute on the span' => ['<p><span data-carve-substitution class="x"><del>o</del><ins>n</ins></span></p>'],
            'text between the halves' => ['<p><span data-carve-substitution><del>o</del> <ins>n</ins></span></p>'],
            'the halves reversed' => ['<p><span data-carve-substitution><ins>n</ins><del>o</del></span></p>'],
            'an attribute on a half' => ['<p><span data-carve-substitution><del class="x">o</del><ins>n</ins></span></p>'],
            'no carrier' => ['<p><del>o</del><ins>n</ins></p>'],
        ];
    }

    #[DataProvider('provideNoSubstitution')]
    public function testReadsNoSubstitution(string $html): void
    {
        $source = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('{~', $source);
        $this->assertStringNotContainsString('data-carve-substitution', $source);
    }
}
