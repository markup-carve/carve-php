<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * An admonition carried a span while the inlines of its TITLE did not, which was
 * 9 of the 26 remaining position findings (carve-php#579).
 *
 * The title reaches the inline parser as a regex capture out of an
 * already-split class string, so it was parsed with no source map and nothing
 * inside it could be mapped back.
 */
class AdmonitionTitlePositionTest extends TestCase
{
    /**
     * @return array<string, mixed> the decoded AST
     */
    protected function ast(string $source): array
    {
        $parser = new BlockParser(false, false, false, true);

        return (new AstCodec())->encode($parser->parse($source));
    }

    protected function sliceOf(string $source, array $pos): string
    {
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_slice($codepoints, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));
    }

    public function testATitleSInlineContentIsPlaced(): void
    {
        $source = "::: note \"Pro Tip\"\nbody\n:::\n";
        $ast = $this->ast($source);
        $title = $ast['children'][0]['title'][0];

        $this->assertArrayHasKey('pos', $title, 'a title\'s inline content must carry a position');
        $this->assertSame('Pro Tip', $this->sliceOf($source, $title['pos']));
    }

    /**
     * The case the lookup had to be written carefully for. Searching the opener
     * line for the bare title matches the TYPE WORD first here, which would put
     * every inline in the title four columns too far left. The quoted form
     * cannot collide, because the type word carries no quotes.
     */
    public function testATitleEqualToItsTypeWordResolvesToTheQuotedOne(): void
    {
        $source = "::: note \"note\"\nbody\n:::\n";
        $ast = $this->ast($source);
        $pos = $ast['children'][0]['title'][0]['pos'];

        $this->assertSame(10, $pos['startOffset'], 'the span landed on the type word, not the quoted title');
        $this->assertSame('note', $this->sliceOf($source, $pos));
    }

    /**
     * A title holding markup must place each piece, not only the first - a fix
     * that anchored the run's start and lost the rest would pass the simple case.
     */
    public function testEachPieceOfAMarkedUpTitleIsPlaced(): void
    {
        $source = "::: note \"Install *now* via `npm`\"\nbody\n:::\n";
        $ast = $this->ast($source);
        $title = $ast['children'][0]['title'];

        $checked = 0;
        foreach ($title as $node) {
            if (($node['type'] ?? '') === 'text') {
                $this->assertArrayHasKey('pos', $node, 'a title text run must be placed');
                $this->assertSame($node['value'], $this->sliceOf($source, $node['pos']));
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(2, $checked, 'expected several text runs in the title');
    }

    /**
     * With position tracking off, nothing is placed - and asking for a span
     * anyway must not cost anything or invent one.
     */
    public function testNoSpanIsBuiltWhenTrackingIsOff(): void
    {
        $parser = new BlockParser(false, false, false, false);
        $ast = (new AstCodec())->encode($parser->parse("::: note \"Pro Tip\"\nbody\n:::\n"));

        $this->assertArrayNotHasKey('pos', $ast['children'][0]['title'][0]);
    }

    /**
     * An EMPTY quoted title has no content to place. Searching the opener line
     * for `""` would match at the quote rather than at any content, so the
     * lookup declines instead.
     */
    public function testAnEmptyTitleIsDeclinedRatherThanPlacedAtTheQuote(): void
    {
        $ast = $this->ast("::: note \"\"\nbody\n:::\n");
        $title = $ast['children'][0]['title'] ?? [];

        foreach ($title as $node) {
            $this->assertArrayNotHasKey('pos', $node, 'an empty title must not be placed');
        }
        $this->assertArrayHasKey('pos', $ast['children'][0], 'the admonition itself is still placed');
    }

    /**
     * A title is optional, and an admonition without one must be unaffected.
     */
    public function testAnAdmonitionWithoutATitleStillParses(): void
    {
        $ast = $this->ast("::: note\nbody\n:::\n");

        $this->assertArrayNotHasKey('title', $ast['children'][0]);
        $this->assertArrayHasKey('pos', $ast['children'][0]);
    }
}
