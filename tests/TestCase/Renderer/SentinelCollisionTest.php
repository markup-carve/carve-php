<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The writer's sentinels cannot collide with authored text.
 *
 * A code block reproduces arbitrary bytes, so `fmt` may not rewrite them. It
 * did: the writer protected verbatim content with the FIXED code points
 * U+E001..U+E004 and restored them unconditionally, so an authored occurrence
 * was indistinguishable from one the writer had inserted -
 *
 *   U+E001 -> a space
 *   U+E002 -> a tab
 *   U+E003 -> nothing at all
 *   U+E004 -> a space
 *
 * Two of those are worse than the deletion. A space or a tab inside a code
 * block is plausible content, so the document still looks right and the diff
 * reads as a whitespace change. Either way `to_html(fmt(x)) != to_html(x)`,
 * which is PART 11 section 1 (carve#678; carve-js fixed it the same way in
 * carve-js#666).
 *
 * The sentinels are now chosen per render from code points the document does
 * not contain, which cannot collide by construction.
 *
 * U+E000 is deliberately NOT covered here. It is the parser's in-band marker
 * for a non-breaking space, shared with the HTML, plain, ANSI and Markdown
 * renderers, so an authored U+E000 is conflated with a parsed nbsp before the
 * writer runs - `convert()` alone turns it into `&nbsp;`. That is the other
 * half of carve#678 and needs a decision about what the parsed text of an nbsp
 * is, not a change in the writer.
 */
class SentinelCollisionTest extends TestCase
{
    protected function fmt(string $source): string
    {
        $converter = new CarveConverter();

        return (new CarveRenderer())->render($converter->parse($source));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function sentinelCodePoints(): array
    {
        return [
            'U+E001 (the space sentinel)' => [0xE001],
            'U+E002 (the tab sentinel)' => [0xE002],
            'U+E003 (the blank-line sentinel)' => [0xE003],
            'U+E004 (the thematic-break guard)' => [0xE004],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sentinelCodePoints')]
    public function testASentinelSurvivesACodeBlock(int $codePoint): void
    {
        $char = (string)mb_chr($codePoint, 'UTF-8');
        $source = "```\na\n{$char}\nb\n```\n";

        $out = $this->fmt($source);

        $this->assertStringContainsString(
            $char,
            $out,
            sprintf('U+%04X was rewritten by the writer', $codePoint),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sentinelCodePoints')]
    public function testTheRoundTripInvariantHolds(int $codePoint): void
    {
        $char = (string)mb_chr($codePoint, 'UTF-8');
        $source = "```\na\n{$char}\nb\n```\n";
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert($this->fmt($source)),
            sprintf('to_html(fmt(x)) != to_html(x) for U+%04X (PART 11 section 1)', $codePoint),
        );
    }

    /**
     * The sentinels still have to DO their job. These two are what a fix here
     * could plausibly break: both rely on the protection the sentinels provide,
     * and both would pass a writer that simply stopped protecting anything.
     */
    public function testABlankLineInsideACodeBlockSurvives(): void
    {
        $out = $this->fmt("```\na\n\nb\n```\n");

        $this->assertStringContainsString("a\n\nb", $out);
    }

    public function testTrailingWhitespaceInsideACodeBlockSurvives(): void
    {
        $out = $this->fmt("```\na   \nb\n```\n");

        $this->assertStringContainsString("a   \n", $out);
    }

    /**
     * An ARRAY KEY carries authored text too. A document built through the API
     * keys its abbreviations by the term, and the term is written back out, so
     * a scan that only looked at values missed it and the writer rewrote the
     * author's character.
     */
    public function testAnAbbreviationTermIsScannedThoughItIsAKey(): void
    {
        $term = 'A' . (string)mb_chr(0xE001, 'UTF-8');
        $document = (new CarveConverter())->parse("text\n");
        $document->setAbbreviations([$term => 'Expansion']);

        $out = (new CarveRenderer())->render($document);

        $this->assertStringContainsString($term, $out, 'the term lost its private-use character');
    }

    /**
     * A document carrying the DEFAULT sentinels forces the search, and the
     * replacements must not be characters the document also uses. Every
     * private-use code point from U+E001 up is occupied here except the ones
     * past the block written, so the picker has to look past its first
     * candidates.
     */
    public function testAllFourDefaultsPresentAtOnce(): void
    {
        $chars = '';
        foreach ([0xE001, 0xE002, 0xE003, 0xE004] as $codePoint) {
            $chars .= (string)mb_chr($codePoint, 'UTF-8');
        }
        $source = "```\n{$chars}\n```\n";
        $converter = new CarveConverter();

        $out = $this->fmt($source);

        $this->assertStringContainsString($chars, $out);
        $this->assertSame($converter->convert($source), $converter->convert($out));
    }
}
