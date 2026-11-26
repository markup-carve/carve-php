<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6444, C2-B-224, C2-I-072: in a cell a shortcut is keyed by its text after the escaped pipe is read as a pipe.
 */
final class CellShortcutKeyTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cells(): array
    {
        return [
            'finds the pipe definition' => ["| [r\\|s] |\n\n[r|s]: /u\n", '<td><a href="/u">r|s</a></td>'],
            'misses the escaped definition' => ["| [r\\|s] |\n\n[r\\|s]: /u\n", '<td>[r|s]</td>'],
            'image' => ["| ![r\\|s] |\n\n[r|s]: /u\n", '<td><img src="/u" alt="r|s"></td>'],
            'spaces around the pipe' => ["| [r \\| s] |\n\n[r | s]: /u\n", '<td><a href="/u">r | s</a></td>'],
        ];
    }

    #[DataProvider('cells')]
    public function testShortcutKey(string $source, string $cell): void
    {
        $this->assertStringContainsString($cell, Carve::toHtml($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchanged(): array
    {
        return [
            'shortcut image' => ["| ![r\\|s] |\n\n[r|s]: /u\n"],
            'collapsed image' => ["| ![r\\|s][] |\n\n[r|s]: /u\n"],
            'inline image' => ["| ![a\\|b](u) |\n"],
            'pipe behind an escaped backslash' => ["| ![a\\\\\\|b](u) |\n"],
        ];
    }

    /**
     * C2-W-098: an image alt that holds a pipe is spelled in a cell with the pipe escaped, and reports no loss.
     */
    #[DataProvider('unchanged')]
    public function testImageAltWithAPipeWritesBack(string $source): void
    {
        $sidecar = Carve::formatWithDiagnostics($source);
        $this->assertSame($source, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
    }

    public function testOutsideACellTheKeyKeepsTheBackslash(): void
    {
        $this->assertSame('<p><a href="/u">r|s</a></p>', Carve::toHtml("[r\\|s]\n\n[r\\|s]: /u\n"));
    }
}
