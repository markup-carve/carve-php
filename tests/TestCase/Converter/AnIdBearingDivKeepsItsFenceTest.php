<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An imported `<div>` unwraps only when it kept nothing a container is needed
 * for, and an attribute the language can hold is such a thing however few
 * children the element has (markup-carve/carve-php#2518).
 *
 * The importer hoisted the attributes onto the sole child and dropped the
 * element, which moved the id onto a paragraph and reported nothing.
 */
class AnIdBearingDivKeepsItsFenceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function divProvider(): array
    {
        return [
            'one paragraph' => [
                '<div id="mw-navigation"><p>x</p></div>',
                "{#mw-navigation}\n:::\nx\n:::",
            ],
            'one blockquote' => [
                '<div id="intro"><blockquote><p>q</p></blockquote></div>',
                "{#intro}\n:::\n> q\n:::",
            ],
            'one heading' => [
                '<div id="x"><h2>y</h2></div>',
                "{#x}\n:::\n## y\n:::",
            ],
            'a data attribute beside the id' => [
                '<div id="summary" data-type="note"><p>x</p></div>',
                "{#summary data-type=note}\n:::\nx\n:::",
            ],
            'bare text body, already correct' => [
                '<div id="x">y</div>',
                "{#x}\n:::\ny\n:::",
            ],
            'two paragraphs, already correct' => [
                '<div id="x"><p>y</p><p>z</p></div>',
                "{#x}\n:::\ny\n\nz\n:::",
            ],
        ];
    }

    #[DataProvider('divProvider')]
    public function testTheFenceComesBack(string $html, string $expected): void
    {
        $this->assertSame($expected, trim((new HtmlToCarve())->convert($html)));
    }

    /**
     * A div that kept nothing still unwraps, which is the other half of the
     * boundary the contract draws.
     */
    public function testADivThatKeptNothingStillUnwraps(): void
    {
        $this->assertSame('x', trim((new HtmlToCarve())->convert('<div><p>x</p></div>')));
    }
}
