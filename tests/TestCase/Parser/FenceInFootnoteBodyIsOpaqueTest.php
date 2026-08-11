<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A code fence is opaque. A definition-shaped line inside one is a code SAMPLE
 * and registers nothing.
 *
 * This engine knew that everywhere except a footnote body. The reference prepass
 * re-bases a fence opener on the enclosing CONTENT COLUMN, and that column tracks
 * list items only - so inside a note body it was 0, the indented opener matched
 * nothing, the fence went untracked, and the line inside it was collected as a
 * real definition. A reference below the note then resolved against a code sample
 * (carve-php#811).
 *
 * The executable spec and carve-rs both decline this shape. carve-js had the same
 * bug and fixed it the same way in carve-js#668.
 */
class FenceInFootnoteBodyIsOpaqueTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testADefinitionInsideAFenceInANoteBodyRegistersNothing(): void
    {
        $html = $this->html("[^a]: note\n  ```\n  [r]: /u\n  ```\n\nsee[^a] and [t][r]\n");

        $this->assertStringNotContainsString('href="/u"', $html);
        // The reference stays literal, and the code line still renders as code.
        $this->assertStringContainsString('[t][r]', $html);
        $this->assertStringContainsString('<pre><code>', $html);
    }

    public function testADefinitionOutsideTheFenceInTheSameBodyStillRegisters(): void
    {
        // The boundary: the fix must not make the whole note body opaque.
        $html = $this->html("[^a]: note\n  [r]: /u\n\nsee[^a] and [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testCollectionResumesAfterTheCloser(): void
    {
        $html = $this->html("[^a]: note\n  ```\n  x\n  ```\n\n  [r]: /u\n\nsee[^a] and [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testTopLevelStillDeclinesIt(): void
    {
        $this->assertStringNotContainsString('href="/u"', $this->html("```\n[r]: /u\n```\n\n[t][r]\n"));
    }

    public function testABlockQuoteStillDeclinesIt(): void
    {
        $this->assertStringNotContainsString(
            'href="/u"',
            $this->html("> ```\n> [r]: /u\n> ```\n\n[t][r]\n"),
        );
    }

    public function testAListItemStillDeclinesIt(): void
    {
        $this->assertStringNotContainsString(
            'href="/u"',
            $this->html("- ```\n  [r]: /u\n  ```\n\n[t][r]\n"),
        );
    }
}
