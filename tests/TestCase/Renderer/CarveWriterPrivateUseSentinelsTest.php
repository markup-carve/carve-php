<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fmt` may not rewrite the characters an author wrote.
 *
 * The writer used the FIXED sentinels U+E001..U+E004 and restored them
 * unconditionally, so an AUTHORED occurrence was indistinguishable from one the
 * writer inserted: U+E001 and U+E004 came back as a space, U+E002 as a tab,
 * U+E003 as nothing at all. Three of those are worse than a deletion - a space
 * or a tab is plausible content, so the document still looks right and the diff
 * reads as whitespace (carve#678).
 *
 * It was never limited to code blocks either, which is how the issue framed it:
 * a paragraph holding one was corrupted just the same, because the restore runs
 * over the whole document string.
 *
 * The sentinels are now chosen per render from code points the document does not
 * contain, which cannot collide by construction.
 *
 * U+E000 is deliberately not covered. It is the parser's in-band marker for a
 * non-breaking space, shared with the HTML, plain, ANSI and Markdown renderers,
 * so an authored U+E000 is already conflated with a parsed nbsp before the
 * writer runs. That is the other half of carve#678 and needs a decision about
 * what the parsed text of an nbsp is.
 */
class CarveWriterPrivateUseSentinelsTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new BlockParser())->parse($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sentinelProvider(): array
    {
        return [
            'U+E001' => ["\u{E001}"],
            'U+E002' => ["\u{E002}"],
            'U+E003' => ["\u{E003}"],
            'U+E004' => ["\u{E004}"],
        ];
    }

    #[DataProvider('sentinelProvider')]
    public function testItSurvivesInACodeBlock(string $char): void
    {
        $source = "```\na" . $char . "z\n```\n";

        // Byte equality, not "contains": nothing may be substituted.
        $this->assertSame($source, $this->fmt($source));
    }

    #[DataProvider('sentinelProvider')]
    public function testItSurvivesInAParagraph(string $char): void
    {
        $source = 'text a' . $char . "z here\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testAllFourAtOnce(): void
    {
        $source = "```\na\u{E001}\u{E002}\u{E003}\u{E004}z\n```\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testALineHoldingOnlyOneDoesNotVanish(): void
    {
        // The shape carve#678 reported: the line came back empty.
        $source = "```\na\n\u{E003}\nb\n```\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testTheHtmlIsEqualAcrossTheRoundTrip(): void
    {
        // PART 11 §1, which is what the corruption broke.
        $source = "```\na\u{E001}\u{E002}\u{E003}\u{E004}z\n```\n";
        $converter = new CarveConverter();

        $this->assertSame($converter->convert($source), $converter->convert($this->fmt($source)));
    }

    public function testABlankLineInsideACodeBlockStillSurvives(): void
    {
        // What U+E003 exists for. This must keep working, or the fix traded one
        // defect for another.
        $source = "```\na\n\nb\n```\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testTrailingWhitespaceInsideACodeBlockStillSurvives(): void
    {
        // What U+E001 and U+E002 exist for.
        $source = "```\na \t\nb\n```\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testBothRolesAtOnce(): void
    {
        // The document holds a literal U+E001 AND needs a real trailing-space
        // sentinel, so the chosen set has to avoid the authored one.
        $source = "```\na\u{E001}\nb  \nc\n```\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testADeepDocumentStillRefusesRatherThanFailingInTheScan(): void
    {
        // The scan walks the tree. It must not be the thing that breaks on a deep
        // document - the §25 depth refusal is what should fire. A recursive
        // serializer here would hit its nesting limit first, and the node graph
        // carries PARENT references, so an unguarded walk would not terminate at
        // all.
        $ladder = '';
        for ($i = 0; $i < 60; $i++) {
            $ladder .= str_repeat('  ', $i) . "- x\n";
        }

        $out = $this->fmt($ladder);
        $this->assertSame($ladder, $out);
    }
}
