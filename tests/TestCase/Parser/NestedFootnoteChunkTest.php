<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedFootnoteChunkTest extends TestCase
{
    public function testAListChunkStartingWithANoteKeepsItsBody(): void
    {
        foreach ([4, 5, 6] as $indent) {
            $html = (new CarveConverter())->convert("- r[^n]\n\n  [^n]: p\n" . str_repeat(' ', $indent) . "> q\n");
            [$list, $notes] = explode('<section role="doc-endnotes"', $html);
            $this->assertStringNotContainsString('<blockquote>', $list);
            $this->assertStringContainsString('<blockquote><p>q</p></blockquote>', $notes);
        }
    }

    public function testAnOpenerBelowTheNoteFloorStaysWithTheList(): void
    {
        $html = (new CarveConverter())->convert("- r[^n]\n\n  [^n]: p\n   > q\n");
        [$list, $notes] = explode('<section role="doc-endnotes"', $html);
        $this->assertStringContainsString('<blockquote><p>q</p></blockquote>', $list);
        $this->assertStringNotContainsString('<blockquote>', $notes);
    }

    public function testAMarkerLineNoteKeepsTheListOpenerOutsideItsBody(): void
    {
        $html = (new CarveConverter())->convert("- [^n]: p\n    > q\n\nr[^n]\n");
        [$list, $notes] = explode('<section role="doc-endnotes"', $html);
        $this->assertStringContainsString('<blockquote><p>q</p></blockquote>', $list);
        $this->assertStringNotContainsString('<blockquote>', $notes);
    }

    public function testAPlusAttachedNoteOwnsItsBody(): void
    {
        $html = (new CarveConverter())->convert("- a\n+\n[^n]: p\n  > q\n\nr[^n]\n");
        [$list, $notes] = explode('<section role="doc-endnotes"', $html);
        $this->assertStringNotContainsString('<blockquote>', $list);
        $this->assertStringContainsString('<blockquote><p>q</p></blockquote>', $notes);
    }
}
