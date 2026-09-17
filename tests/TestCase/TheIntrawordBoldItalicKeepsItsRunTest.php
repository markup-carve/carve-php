<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
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

    /**
     * The tree is built rather than parsed: PART 9 section 9 E3 reached forced
     * openers, so no Carve source spells a same-kind nesting
     * (markup-carve/carve#2078).
     */
    public function testASameStrengthNestingKeepsItsInlineHtmlForm(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a '],
                        ['type' => 'emphasis', 'children' => [['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'x']]]]],
                        ['type' => 'text', 'value' => ' b'],
                    ],
                ],
            ],
        ]);

        $this->assertSame("a <em>*x*</em> b\n", CarveConverter::markdown()->render($document));
    }
}
