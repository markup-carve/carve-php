<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-I-047: the `/` kind escapes each `/` that can open by the characters written around it, also where the
 * `/` ends a text and the next character is the markup of another node. Outputs are carve-js `next2`.
 */
final class DelimiterKindEdgeTest extends TestCase
{
    public function testASlashBeforeAFootnoteReferenceTakesTheKind(): void
    {
        $source = "^[/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n";

        $result = Carve::formatWithDiagnostics($source, ['normalize' => true]);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame("^\\[\\/\\`[\\`\\[\\/[^-]\\]][<] and [lck \\[i0][k] text \\/i0 [c][]\n", $result->source);
    }

    public function testThePlainFormIsUnchanged(): void
    {
        $source = "^[/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n";

        $this->assertSame("^\\[\\/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n", Carve::formatWithDiagnostics($source)->source);
    }
}
