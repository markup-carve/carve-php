<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inside verbatim content, the no-break-space sentinel is written as the
 * CHARACTER, not as `\ `.
 *
 * `normalize()` rewrites every U+E000 to `\ `, which is right OUTSIDE verbatim
 * content and wrong inside it: escapes do not resolve in a code block or a code
 * span, so `\ ` there is a literal backslash followed by a space. The round trip
 * broke - a code block holding `&nbsp;` came back holding `\ ` (carve-php#829).
 *
 * An authored U+E000 now travels through normalization under its own per-render
 * sentinel, out of reach of that rewrite, and `restoreVerbatim()` puts the
 * character back. This engine already chose four sentinels per render; the nbsp
 * carrier is a FIFTH, so the search steps by five.
 *
 * WIDER THAN THE REPORT, which showed the sentinel alone on a line inside a
 * fence. Measured, it also broke inline within a fenced line, in a tilde fence, a
 * raw block, a block comment, an inline CODE SPAN and a literal inline. The code
 * span needed its own line: `renderCode()` emits content verbatim and never went
 * through `protectVerbatim()`.
 *
 * Every expectation here was checked against carve-rs byte-for-byte, which is
 * correct in all of these. carve-js had the same defect (carve-js#689).
 */
class NbspSentinelInVerbatimTest extends TestCase
{
    /**
     * The parser's in-band marker for a resolved no-break space.
     *
     * @var string
     */
    protected const NBSP = "\u{E000}";

    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * PART 11 §1: formatting must not change what the document says.
     */
    protected function assertRoundTrips(string $source): void
    {
        $this->assertSame($this->html($source), $this->html($this->fmt($source)), 'the round trip broke');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function verbatimShapes(): array
    {
        $nbsp = "\u{E000}";

        return [
            'fenced block, alone on a line' => ["```\na\n" . $nbsp . "\nb\n```\n"],
            'fenced block, inline in a line' => ["```\na" . $nbsp . "z\n```\n"],
            'tilde fence' => ["~~~\na" . $nbsp . "z\n~~~\n"],
            'raw block' => ["```=html\n<b>a" . $nbsp . "z</b>\n```\n"],
            'block comment' => ["%%%\na" . $nbsp . "z\n%%%\n"],
            // A different path: renderCode() emits content verbatim and does not
            // go through protectVerbatim(), so the block fix did not reach it.
            'inline code span' => ['a `x' . $nbsp . 'y` b' . "\n"],
            'literal inline' => ['a !`x' . $nbsp . 'y` b' . "\n"],
            // The all-space padding rule in renderCode() is the neighbouring
            // logic; this pins that carrying the sentinel does not trip it.
            'code span of only the sentinel' => ['a `' . $nbsp . '` b' . "\n"],
        ];
    }

    #[DataProvider('verbatimShapes')]
    public function testTheSentinelSurvivesVerbatimContent(string $source): void
    {
        $this->assertStringContainsString(self::NBSP, $this->fmt($source), 'the character was rewritten');
        $this->assertRoundTrips($source);
    }

    #[DataProvider('verbatimShapes')]
    public function testNoBackslashEscapeIsWrittenInVerbatimContent(string $source): void
    {
        // The specific corruption: `\ ` in place of the character.
        $this->assertStringNotContainsString('\\ ', $this->fmt($source));
    }

    public function testItIsStillAnEscapeOutsideVerbatimContent(): void
    {
        // The boundary. The sentinel means the author wrote an ESCAPED SPACE, and
        // emitting U+00A0 or a raw sentinel here would lose the distinction the
        // parser draws (carve#369).
        $source = 'a' . self::NBSP . "b\n";

        $this->assertSame("a\\ b\n", $this->fmt($source));
        $this->assertRoundTrips($source);
    }

    public function testASourceWrittenBackslashInACodeBlockStaysLiteral(): void
    {
        // The control that explains why `\ ` cannot be the spelling inside a
        // fence: a backslash written in the SOURCE of a code block stays a
        // backslash, in all three engines. If `\ ` meant a no-break space there,
        // these two documents would be indistinguishable.
        $source = "```\na\n\\ \nb\n```\n";

        $this->assertStringContainsString('\\', $this->html($source));
        $this->assertRoundTrips($source);
    }

    public function testFormattingIsIdempotent(): void
    {
        // A carrier sentinel that leaked would show up on the second pass.
        $source = "```\na" . self::NBSP . "z\n```\n";
        $once = $this->fmt($source);

        $this->assertSame($once, $this->fmt($once));
    }
}
