<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition in a description is collected even inside a container.
 *
 * PART 8 states it in as many words: "Definitions are collected even inside a
 * block-level container, which then renders as though the definition line were
 * never written" (markup-carve/carve#801, §17 L6).
 *
 * This engine did the second half without the first. The block parser emptied
 * the `dd` - so the line WAS consumed - while the extractor's description test
 * read the raw previous line, found a `>` or a `-` in front of the term, and
 * answered "no term above this". The marker was left in place, nothing
 * registered, and the reference the definition fed stayed literal somewhere
 * else in the document. Consumed and lost, which is worse than either answer
 * (markup-carve/carve#840).
 */
class DefinitionInADescriptionInsideAContainerTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testADefinitionInsideABlockQuoteResolves(): void
    {
        $this->assertStringContainsString(
            '<a href="/u">t</a>',
            $this->html("> :: term\n> :  [r]: /u\n>\n> see [t][r]\n"),
        );
    }

    public function testADefinitionInsideAListItemResolves(): void
    {
        $this->assertStringContainsString(
            '<a href="/u">t</a>',
            $this->html("- :: term\n  :  [r]: /u\n\nsee [t][r]\n"),
        );
    }

    public function testAFootnoteInsideABlockQuoteResolves(): void
    {
        $this->assertStringContainsString(
            'doc-noteref',
            $this->html("> :: term\n> :  [^f]: x\n>\n> see[^f]\n"),
        );
    }

    public function testTheDescriptionIsStillEmptied(): void
    {
        // The other half of the same rule: the container renders as though the
        // line were never written. Collecting must not put it back.
        $this->assertStringContainsString('<dd></dd>', $this->html("> :: term\n> :  [r]: /u\n>\n> see [t][r]\n"));
    }

    public function testADocumentLevelDescriptionIsUnchanged(): void
    {
        // The control: the shape that already worked must keep working.
        $this->assertStringContainsString(
            '<a href="/u">t</a>',
            $this->html(":: term\n:  [r]: /u\n\nsee [t][r]\n"),
        );
    }

    public function testADescriptionLineWithNoTermAboveItStillDefinesNothing(): void
    {
        // The edge this fix must not have widened past: corpus 216. A `: ` line
        // whose predecessor is prose is not a description, so the
        // definition-shaped content on it defines nothing - inside a container
        // as much as outside one.
        $this->assertStringContainsString('see [t][r]', $this->html("> text\n> : [r]: /u\n>\n> see [t][r]\n"));
        $this->assertStringContainsString('see [t][r]', $this->html(": [r]: /u\n\nsee [t][r]\n"));
    }
}
