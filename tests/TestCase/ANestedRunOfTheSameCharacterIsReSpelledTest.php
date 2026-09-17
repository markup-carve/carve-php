<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1982. A parent and its only child that spell their delimiters with
 * the same character emit one run on each side, and the reader re-pairs it by
 * its own rule. Where the two strengths DIFFER that is the round-trip
 * normalization list's second entry and the document is the same; where they
 * are EQUAL the runs collapse into one element of the wrong kind.
 *
 * Read back with markdown-it-py 3.0.0 (`commonmark` preset, `strikethrough`
 * enabled) and pulldown-cmark 0.13.4; both give the same answer here.
 */
class ANestedRunOfTheSameCharacterIsReSpelledTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    private function html(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }

    /**
     * A same-kind nesting has no Carve source since PART 9 section 9 E3 reached
     * forced openers (markup-carve/carve#2078), so these trees are built rather
     * than parsed.
     *
     * @param array<int, array<string, mixed>> $children
     */
    private function paragraph(array $children): Document
    {
        return (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => $children]],
        ]);
    }

    /**
     * @param string $type
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private static function span(string $type, array $children): array
    {
        return ['type' => $type, 'children' => $children];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $value): array
    {
        return ['type' => 'text', 'value' => $value];
    }

    public function testEmphasisInsideEmphasisIsSeparatedWhereFourAsterisksReadAsOneStrong(): void
    {
        $document = $this->paragraph([self::span('emphasis', [self::span('emphasis', [self::text('x')])])]);

        $this->assertSame("<em>*x*</em>\n", CarveConverter::markdown()->render($document));
        $this->assertSame("<p><em><em>x</em></em></p>\n", (new CarveConverter())->render($document));
    }

    public function testStrongInsideStrongIsSeparatedWhereEightAsterisksSpellOnlyByLuck(): void
    {
        $document = $this->paragraph([self::span('strong', [self::span('strong', [self::text('x')])])]);

        $this->assertSame("<strong>**x**</strong>\n", CarveConverter::markdown()->render($document));
    }

    public function testABoldItalicIsLeftAloneBecauseItsTwoStrengthsCommute(): void
    {
        $this->assertSame("***x***\n", $this->md("/*x*/\n"));
        $this->assertSame("***x***\n", $this->md("/{*x*}/\n"));
    }

    public function testPaddingAroundANestedRunDoesNotCountAsContent(): void
    {
        $this->assertSame("a ***b*** c\n", $this->md("a{* {/b/} *}c\n"));
    }

    public function testANestingWhoseTwoSpellingsShareNoCharacterIsLeftAlone(): void
    {
        $this->assertSame("*~~x~~*\n", $this->md("/{~x~}/\n"));
    }

    /**
     * markup-carve/carve-php#1999: a child of a DIFFERENT strength at an edge
     * nests, so the run stays and the engines write the same bytes. The ruling
     * recorded on markup-carve/carve-js#1736 picks the plain spelling.
     */
    public function testAStrongThatClosesAnEmphasisKeepsTheRun(): void
    {
        $this->assertSame("*italic **bold***\n", $this->md("{/italic *bold*/}\n"));
    }

    public function testAnEmphasisThatClosesAStrongKeepsTheRun(): void
    {
        $this->assertSame("**bold *italic***\n", $this->md("{*bold /italic/*}\n"));
    }

    public function testAChildThatOpensTheContentKeepsTheRun(): void
    {
        $this->assertSame("***bold** italic*\n", $this->md("{/*bold* italic/}\n"));
    }

    public function testTheRunStaysIntraword(): void
    {
        $this->assertSame("a*x **y***b\n", $this->md("a{/x *y*/}b\n"));
    }

    public function testAnEqualStrengthChildThatOpensTheContentIsReSpelled(): void
    {
        $document = $this->paragraph([
            self::span('emphasis', [self::span('emphasis', [self::text('x')]), self::text(' tail')]),
        ]);

        $this->assertSame("<em>*x* tail</em>\n", CarveConverter::markdown()->render($document));
    }

    public function testAnEqualStrengthChildThatClosesItIsReSpelled(): void
    {
        $document = $this->paragraph([
            self::span('emphasis', [self::text('head '), self::span('emphasis', [self::text('x')])]),
        ]);

        $this->assertSame("<em>head *x*</em>\n", CarveConverter::markdown()->render($document));
    }

    public function testALiteralTheRendererDidNotEscapeAtTheEdgeIsReSpelled(): void
    {
        $document = $this->paragraph([
            self::span('emphasis', [self::span('emphasis', [self::text('**x')])]),
        ]);

        $this->assertSame("<em>*\\*\\*x*</em>\n", CarveConverter::markdown()->render($document));
    }

    public function testAnEscapedEdgeCharacterDoesNotReachTheRun(): void
    {
        $this->assertSame("*x\\**\n", $this->md("{/x\\*/}\n"));
    }
}
