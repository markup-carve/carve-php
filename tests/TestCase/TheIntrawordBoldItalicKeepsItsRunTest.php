<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1992. The flanking pass read the character at the EDGE of the core
 * as the character inside the delimiter run. For a bold-italic that edge
 * character is the child's own delimiter, which is part of the run the reader
 * lexes rather than content beside it.
 *
 * Read back with markdown-it-py 3.0.0 (`commonmark` preset, `strikethrough`
 * enabled) and pulldown-cmark 0.13.4.
 */
class TheIntrawordBoldItalicKeepsItsRunTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runProvider(): array
    {
        return [
            'intraword on the closing side' => ['a /*x*/b', 'a ***x***b'],
            'intraword on both sides' => ['a/*x*/b', 'a***x***b'],
            'content opening with an escape' => ['a /*~x*/b', 'a ***\~x***b'],
            'content opening with an escaped asterisk' => ['a /**x*/b', 'a ***\*x***b'],
            'the explicit nesting' => ['a {*{/x/}*}b', 'a ***x***b'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('runProvider')]
    public function testTheRunIsKept(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", $this->md($source . "\n"));
    }

    /**
     * `a ***x\~***b` and `a***\~x***b` come back as literal text in both
     * readers: a run whose inside character is punctuation needs a neighbour
     * that is whitespace or punctuation, and a word character is neither.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fallbackProvider(): array
    {
        return [
            'content closing with an escape' => ['a /*x~*/b', 'a <strong>*x\~*</strong>b'],
            'content closing with a bang' => ['a /*x!*/b', 'a <strong>*x!*</strong>b'],
            'intraword with an opening escape' => ['a/*~x*/b', 'a<strong>*\~x*</strong>b'],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('fallbackProvider')]
    public function testTheFallbackStaysWhereTheRunCannotFlank(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", $this->md($source . "\n"));
    }

    public function testASameStrengthNestingKeepsItsInlineHtmlForm(): void
    {
        $this->assertSame("a <em>*x*</em> b\n", $this->md("a /{/x/}/ b\n"));
    }
}
