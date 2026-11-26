<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriterNoteAndImageKeyTest extends TestCase
{
    /**
     * C2-W-125 case five escapes a caret of text only; an inline footnote that starts span or link text stays one.
     *
     * @return array<string, array{string}>
     */
    public static function leadingNotes(): array
    {
        return [
            'span after a bang' => ["*s ![^[n]%% c]{.k}\n"],
            'span' => ["[^[n] x]{.k}\n"],
            'link' => ["[^[n] x](/u)\n"],
        ];
    }

    #[DataProvider('leadingNotes')]
    public function testAnInlineFootnoteAtTheStartOfBracketedTextIsNotEscaped(string $source): void
    {
        $this->assertSame($source, Carve::format($source));
        $this->assertSame($source, Carve::write(Carve::parse($source)));
    }

    public function testACaretOfTextAtTheStartOfSpanTextIsStillEscaped(): void
    {
        $tree = Carve::parse("[\\^x]{.k}\n");

        $this->assertSame("[\\^x]{.k}\n", Carve::write($tree));
    }

    /**
     * C2-W-075: the alt text of a collapsed or shortcut image folds to its key (C2-I-079), which keeps its backslash.
     *
     * @return array<string, array{string}>
     */
    public static function escapedKeys(): array
    {
        return [
            'shortcut pipe' => ["![\\|]\n\n[\\|]: /u\n"],
            'collapsed pipe' => ["![\\|][]\n\n[\\|]: /u\n"],
            'shortcut star' => ["![a\\*b]\n\n[a\\*b]: /u\n"],
        ];
    }

    #[DataProvider('escapedKeys')]
    public function testAShortcutImageKeepsTheBackslashOfItsKey(string $source): void
    {
        $this->assertSame($source, Carve::write(Carve::parse($source)));
    }
}
