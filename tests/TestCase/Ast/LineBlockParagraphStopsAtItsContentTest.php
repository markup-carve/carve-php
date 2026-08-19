<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block's paragraph ends where its content does.
 *
 * The stanza's extent was pure line geometry - first line start to last line
 * end - which is right wherever the line is taken whole. A line block's content
 * rule does not take it whole: PART 2 drops a trailing ONE-COLUMN whitespace
 * run, so the paragraph covered a space its content does not contain
 * (carve-php#1363). PART 12 §4 has a span end immediately after the last
 * source codepoint the construct owns.
 *
 * The rule has TWO halves and only one of them drops. §23 turns an inner or
 * trailing run of TWO OR MORE columns into non-breaking-space CONTENT, and
 * content is inside the span - so the fix cannot be "trim trailing whitespace",
 * which is why both halves are pinned below.
 */
class LineBlockParagraphStopsAtItsContentTest extends TestCase
{
    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    private function paragraph(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $encoded = (new AstCodec())->encode($converter->parse($source));

        return $encoded['children'][0]['children'][0];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function stanzaProvider(): array
    {
        return [
            // The reported document. `def ` drops its one trailing column, so
            // the paragraph ends after the `f` at offset 15. carve-js and
            // carve-rs both publish 6-15.
            'a dropped one-column run at the end' => ["::: |\nabc  \ndef \n:::\n", 15, 4],
            // Two columns are CONTENT, so they are inside the span. Getting
            // this wrong in the other direction is just as bad.
            'a kept two-column run at the end' => ["::: |\ndef  \n:::\n", 11, 6],
            // Nothing to drop.
            'no trailing whitespace' => ["::: |\ndef\n:::\n", 9, 4],
            // Only the LAST line of the stanza decides the end; an interior
            // line's dropped run is inside the paragraph either way.
            'a dropped run on an interior line' => ["::: |\nabc \ndef\n:::\n", 14, 4],
        ];
    }

    #[DataProvider('stanzaProvider')]
    public function testTheParagraphEndsAtTheLastCodepointItOwns(string $source, int $endOffset, int $endColumn): void
    {
        $paragraph = $this->paragraph($source);

        $this->assertSame('paragraph', $paragraph['type']);
        $this->assertSame($endOffset, $paragraph['pos']['endOffset']);
        $this->assertSame($endColumn, $paragraph['pos']['endColumn']);
    }

    public function testTheEnclosingLineBlockStillCoversItsCloser(): void
    {
        // Only the stanza narrowed. The block itself is delimited by its fences
        // and still reaches the `:::`.
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $encoded = (new AstCodec())->encode($converter->parse("::: |\nabc  \ndef \n:::\n"));

        $this->assertSame('line_block', $encoded['children'][0]['type']);
        $this->assertSame(0, $encoded['children'][0]['pos']['startOffset']);
        $this->assertSame(20, $encoded['children'][0]['pos']['endOffset']);
    }

    public function testTheRenderedHtmlIsUnchanged(): void
    {
        // A span moved; nothing the reader sees did.
        $html = (new CarveConverter())->convert("::: |\nabc  \ndef \n:::\n");

        $this->assertStringContainsString('abc&nbsp;&nbsp;<br>', $html);
        $this->assertStringContainsString('def', $html);
    }
}
