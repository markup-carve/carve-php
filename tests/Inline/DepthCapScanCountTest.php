<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-I-118: an opener's depth is the count of open entries when the scan reads it.
 */
final class DepthCapScanCountTest extends TestCase
{
    /**
     * The pairing pass can close delimiters across a bracket that never closes, which the scan could not.
     */
    public function testBracketBelowCapAtScanFormsWhenPairingSeesMoreEntries(): void
    {
        $line = '[a *[a](u)*[x]: /u';
        $source = implode("\n", [...array_fill(0, 152, $line), ')*/u', ...array_fill(0, 6, $line)]);

        $this->assertStringEndsWith(
            "\n" . '[a <strong><a href="u">a</a></strong>[x]: /u</p>',
            Carve::render($source),
        );
    }

    /**
     * An unclosed `^[` with no caret pair behind it stays an open entry, as the first scan read it.
     */
    public function testUnclosedNoteWithoutInnerCaretPairCounts(): void
    {
        $line = '[a *[a][]*';
        $source = implode("\n", [...array_fill(0, 120, $line), '^[a *[a][]:', ...array_fill(0, 80, $line)]);
        $html = Carve::render($source);

        $this->assertSame(197, substr_count($html, '<strong>'));
        $this->assertStringEndsWith("\n[a *[a][]</strong>\n[a *[a][]*\n[a *[a][]*\n[a *[a][]*</p>", $html);
    }

    public function testFallbackBareOpenerKeepsItsScanCount(): void
    {
        $html = Carve::render(str_repeat('{^', 199) . '[*}a*');

        $this->assertStringNotContainsString('<strong>', $html);
    }
}
