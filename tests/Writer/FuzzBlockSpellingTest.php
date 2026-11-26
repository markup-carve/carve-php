<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuzzBlockSpellingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function spellableBlocks(): array
    {
        return [
            'hard break cell' => ['|\\ |', "| \\  |\n"],
            'footnote shaped paragraph' => [' [^1]: n', "[^1]\\: n\n"],
            'link shaped paragraph' => ["\t[a]: u 'T' {c}", "\\[a]: u 'T' {c}\n"],
            'paragraph after an existing link definition' => ["[a]: u\n [a]: v", "[a]: u\n\n[a]\\: v\n"],
            'caption number mark as text' => ["![]()\n^ \\#", "![]()\n^ \\#\n"],
            'text quote mark before comment' => [">\t%%", "\\> %%\n"],
            'block comment inside stanza' => ["::: |\na\n%%%\nx\n%%%\nb", "::: |\na\n%%%\nx\n%%%\nb\n:::\n"],
            'text byte order mark' => [" \u{FEFF}", "\n\u{FEFF}\n"],
        ];
    }

    #[DataProvider('spellableBlocks')]
    public function testBlockSourceKeepsTheReading(string $input, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($input);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected, $result->source);
        self::assertSame(Carve::toHtml($input), Carve::toHtml($result->source));
        self::assertSame($result->source, Carve::format($result->source));
    }

    public function testLineBlockContentThatClosesItsFenceReportsTheLoss(): void
    {
        $result = Carve::formatWithDiagnostics(":::: |\n :::");
        self::assertSame('structure-unspellable', $result->diagnostics[0]->code);
    }
}
